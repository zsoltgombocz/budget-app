<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Month\MarkLineDone;
use App\Actions\Month\SetLineThisMonth;
use App\Enums\DueState;
use App\Enums\Pace;
use App\Models\Period;
use App\Models\User;
use App\Services\Companion;
use App\Services\Data\CompanionLine;
use App\Services\Data\MonthLine;
use App\Services\Data\MonthView;
use App\Services\Data\WatchState;
use App\Services\MonthService;
use App\Services\PeriodService;
use App\Services\Streak;
use App\Services\Watching;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Today (2.0): what the month still has, the daily allowance that keeps it lasting, today's fixed
 * lines, and Morzsa. The numbers come from MonthService / MonthBudget; nothing is worked out here.
 */
new #[Title('Today')] class extends Component {
    /** @var list<string> milestones reached since the last visit, celebrated once (Rewards) */
    public array $celebrate = [];

    public function mount(): void
    {
        $rewards = resolve(\App\Services\Rewards::class);
        $rewards->sync($this->user(), resolve(PeriodService::class)->today($this->user()->settings()));
        $this->celebrate = $rewards->takeCelebrations($this->user());
    }

    #[Computed]
    public function month(): MonthView
    {
        return resolve(MonthService::class)->current($this->user());
    }

    /**
     * @return list<WatchState>
     */
    #[Computed]
    public function watched(): array
    {
        $watched = [];

        foreach ($this->user()->watchedCategories()->with('category')->orderBy('id')->get() as $watch) {
            $watched[$watch->category_id] = ['name' => $watch->category->name ?? '', 'amount' => $watch->amount];
        }

        return resolve(Watching::class)->states($watched, $this->month->spentByCategory);
    }

    #[Computed]
    public function streak(): int
    {
        return resolve(Streak::class)->forUser($this->user(), $this->month->today);
    }

    #[Computed]
    public function companion(): CompanionLine
    {
        return resolve(Companion::class)->forToday($this->month, $this->watched, $this->streak);
    }

    /**
     * The month before this one when it ended but was not closed yet ("Zárás vár").
     */
    #[Computed]
    public function awaitingClose(): ?Period
    {
        return $this->user()->periods()->where('status', 'open')
            ->whereDate('ends_on', '<', $this->month->period->starts_on->toDateString())
            ->latest('starts_on')->first();
    }

    public function markDone(int $budgetLineId): void
    {
        $line = $this->line($budgetLineId);
        $toGoal = resolve(MarkLineDone::class)->mark($this->user(), $this->month->period, $budgetLineId);

        $this->changed();
        $this->dispatch('app-toast',
            title: $line->isSavings() ? __(':name set aside', ['name' => $line->name]) : __(':name done', ['name' => $line->name]),
            subtitle: $toGoal !== null ? __('Goal: +:amount', ['amount' => money($toGoal)]) : __('The numbers did not change.'),
            undo: 'today-undo-done',
            params: ['budgetLineId' => $budgetLineId],
        );
    }

    #[On('today-undo-done')]
    public function undoDone(int $budgetLineId): void
    {
        resolve(MarkLineDone::class)->undo($this->user(), $this->month->period, $budgetLineId);
        $this->changed();
    }

    /**
     * "Más összeg volt": this month the line counts with another amount.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function setAmount(int $budgetLineId, string $value): array
    {
        $line = $this->line($budgetLineId);
        $amount = Money::parse($value, $this->user()->settings()->currency);

        if ($amount === null || $amount <= 0) {
            return ['ok' => false, 'error' => __('Enter an amount.')];
        }

        $before = $this->month->state->remaining;
        resolve(SetLineThisMonth::class)->amount($this->user(), $this->month->period, $budgetLineId, $amount);
        $this->changed();

        $this->dispatch('app-toast',
            title: __(':name: :amount this month', ['name' => $line->name, 'amount' => money($amount)]),
            subtitle: __('This month now has :amount.', ['amount' => money($this->month->state->remaining)]),
            undo: 'today-undo-amount',
            params: ['budgetLineId' => $budgetLineId, 'before' => $before],
        );

        return ['ok' => true, 'error' => null];
    }

    #[On('today-undo-amount')]
    public function undoAmount(int $budgetLineId): void
    {
        resolve(SetLineThisMonth::class)->amount($this->user(), $this->month->period, $budgetLineId, null);
        $this->changed();
    }

    public function markNoSpend(): void
    {
        resolve(MarkNoSpendDay::class)->handle($this->user());
        $this->changed();
    }

    public function unmarkNoSpend(): void
    {
        resolve(MarkNoSpendDay::class)->unmark($this->user());
        $this->changed();
    }

    /**
     * The closing toast's "Visszavonás" (the closing page sends here after closing).
     */
    #[On('undo-close')]
    public function undoClose(int $periodId): void
    {
        try {
            $period = resolve(\App\Actions\Budget\ReopenPeriod::class)->handle($this->user(), $this->user()->periods()->findOrFail($periodId));
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->dispatch('app-toast', title: (string) collect($exception->errors())->flatten()->first(), icon: 'error');

            return;
        }

        $this->dispatch('app-toast', title: __('Closing undone.'), subtitle: __(':month is open again.', ['month' => \App\Support\Dates::monthName($period->nameDate())]), icon: 'undo');
        $this->redirectRoute('month', ['periodus' => $period->id], navigate: true);
    }

    #[On('budget-updated')]
    public function refresh(): void
    {
        unset($this->month, $this->watched, $this->streak, $this->companion, $this->awaitingClose);
    }

    private function changed(): void
    {
        $this->refresh();
        $this->dispatch('budget-updated');
    }

    private function line(int $budgetLineId): MonthLine
    {
        foreach ($this->month->lines as $line) {
            if ($line->lineId === $budgetLineId) {
                return $line;
            }
        }

        abort(404);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $view = $this->month;
    $state = $view->state;
    $today = $view->today;
    $companion = $this->companion;
    $currency = user_currency();
    $heading = \Illuminate\Support\Str::ucfirst($today->locale(app()->getLocale())->isoFormat('dddd')).', '.\App\Support\Dates::short($today);
    $paceClass = match ($state->pace) {
        Pace::Good => 'bg-accent-tint text-accent-strong',
        Pace::Fast => 'bg-surface-2 text-ink',
        Pace::SlowDown => 'bg-warn-tint text-warn-text',
    };
    $barPercent = $state->dailyAllowance > 0 ? min(100, intdiv($state->spentToday * 100, $state->dailyAllowance)) : ($state->spentToday > 0 ? 100 : 0);
    $chip = collect($this->watched)->first(fn ($watch) => $watch->isOver() || $watch->isNearing());
    $rows = $view->todayRows();
    $howRows = [
        [__('This month’s money'), money($state->spendable)],
        [__('Spent so far'), $state->spent > 0 ? '−'.money($state->spent) : money(0)],
        [__('Left this month'), money($state->remaining)],
        [__('Days left, today included'), trans_choice('{1} :count day|[2,*] :count days', $state->daysLeft, ['count' => $state->daysLeft])],
        [__('Daily allowance'), money($state->dailyAllowance)],
    ];
@endphp

<div class="flex min-h-[calc(100dvh-var(--safe-top)-var(--tabbar-h)-24px-env(safe-area-inset-bottom))] flex-col" x-data="todayPage({ decimals: @js($currency->decimals()), locale: @js(app()->getLocale()), date: @js($today->toDateString()) })">
    <h1 class="sr-only">{{ __('Today') }}</h1>

    <header class="flex h-[52px] items-center justify-between pr-3 pl-5">
        <span class="text-[15px] font-semibold" data-test="today-date">{{ $heading }}</span>
        @if ($this->streak > 0)
            <span class="num inline-flex h-8 items-center gap-1.5 rounded-full bg-surface-2 pr-3 pl-2 text-[15px] font-semibold"
                  aria-label="{{ trans_choice('{1} Streak: :count day|[2,*] Streak: :count days', $this->streak, ['count' => $this->streak]) }}" data-test="streak">
                <svg class="size-5" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10.5h12c0 5.8-3 9.6-6 10.2-3-.6-6-4.4-6-10.2z" fill="#E9C38F"/><path d="M4.6 11c0-3.8 3.4-6 7.4-6s7.4 2.2 7.4 6z" fill="#8A5A34"/><path d="M12 .8c2 1.6 2.6 3 1.6 4.6h-3.2c-1-1.6-.4-3 1.6-4.6z" fill="#E3A462"/></svg>
                {{ $this->streak }}
            </span>
        @endif
    </header>

    @if ($this->awaitingClose)
        <a href="{{ route('close', $this->awaitingClose) }}" wire:navigate class="mx-5 mt-1 flex min-h-[72px] items-center gap-3 rounded-card bg-plum-tint py-3 pr-3 pl-4" data-test="awaiting-close">
            <x-morzsa mood="vidam" :size="44" />
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="text-[15px] font-semibold">{{ __(':month is over', ['month' => \App\Support\Dates::monthName($this->awaitingClose->nameDate())]) }}</span>
                <span class="text-[13px] font-medium text-muted">{{ __('One decision about what is left.') }}</span>
            </span>
            <x-ui.icon name="chevron_right" class="text-muted" />
        </a>
    @endif

    {{-- Morzsa's stage --}}
    <div class="relative h-[150px] shrink-0 bg-[radial-gradient(170px_110px_at_30%_80%,var(--app-lamp),transparent)]">
        <x-morzsa :mood="$companion->mood" :size="124" class="absolute bottom-1 left-6" />
        @if ($companion->text)
            <p role="status" class="absolute top-3 right-4 left-[150px] rounded-[20px] bg-bubble px-4 py-3 text-[15px] leading-snug font-medium shadow-e1 before:absolute before:bottom-4 before:-left-1.5 before:size-3.5 before:rotate-45 before:rounded-[3px] before:bg-bubble" data-test="companion">{{ $companion->text }}</p>
        @endif
    </div>

    {{-- The main number --}}
    <section class="flex flex-col items-center px-5 text-center" data-test="month-left">
        <span class="flex items-center gap-1 text-[15px] font-medium text-ink-2">
            {{ __('Left this month') }}
            <x-explainer topic="month-left" :rows="$howRows" />
        </span>
        <x-ui.amount :value="$state->remaining" size="hero" :tone="$state->remaining < 0 ? 'warn' : null" class="mt-1" />
        <span class="mt-2 inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-semibold {{ $paceClass }}" data-test="pace">
            @if ($state->pace === Pace::Good)<x-ui.icon name="check" :size="16" />@endif
            {{ $state->pace->label() }}
        </span>
        <span class="mt-1 text-[13px] font-medium text-muted">{{ trans_choice('{1} :count day left|[2,*] :count days left', $state->daysLeft, ['count' => $state->daysLeft]) }}</span>
    </section>

    {{-- The daily allowance --}}
    <section class="mx-5 mt-4 rounded-card bg-surface px-4 py-3.5 shadow-e1" data-test="allowance">
        <div class="flex items-baseline justify-between gap-3">
            <span class="text-[13px] font-semibold text-muted">{{ __('Daily allowance') }}</span>
            <x-ui.amount :value="$state->dailyAllowance" size="md" />
        </div>
        <div class="relative mt-2.5 h-2.5 rounded-full bg-surface-2" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $barPercent }}" aria-label="{{ __('Spent today: :amount of :allowance', ['amount' => money($state->spentToday), 'allowance' => money($state->dailyAllowance)]) }}">
            <i @class(['absolute inset-y-0 left-0 block rounded-full', 'bg-warn' => $state->isOverToday(), 'bg-accent' => ! $state->isOverToday()]) style="width: {{ $barPercent }}%"></i>
        </div>
        <div class="mt-2 flex justify-between gap-2 text-[13px] leading-[18px] font-medium text-muted">
            @if ($view->noSpendToday && $state->spentToday === 0)
                <span>{{ __('You didn’t spend today') }}</span>
            @elseif ($state->spentToday === 0)
                <span>{{ __('Nothing spent today yet') }}</span>
            @else
                <span>{{ __('Today so far :amount', ['amount' => money($state->spentToday)]) }}</span>
            @endif
            @if ($state->isOverToday())
                <span class="font-semibold text-warn-text" data-test="over-today">{{ __(':amount over', ['amount' => money($state->overToday())]) }}</span>
            @else
                <span>{!! __('left today :amount', ['amount' => '<b class="num font-semibold text-ink">'.e(money($state->todayLeft)).'</b>']) !!}</span>
            @endif
        </div>
        @if ($state->isOverToday() && $state->tomorrowAllowance !== null)
            <p class="mt-2 border-t border-line pt-2 text-[13px] leading-[18px] font-medium text-ink-2" data-test="tomorrow">{{ __('From tomorrow :amount a day.', ['amount' => money($state->tomorrowAllowance)]) }}</p>
        @endif
    </section>

    @if ($chip)
        <div class="mx-5 mt-2 flex" data-test="watch-chip">
            <span @class(['inline-flex h-[30px] items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold', 'bg-warn-tint text-warn-text' => $chip->isOver(), 'bg-surface-2 text-ink' => ! $chip->isOver()])>
                {{ $chip->isOver()
                    ? __(':category: :amount more than you set', ['category' => $chip->name, 'amount' => money(-$chip->left())])
                    : __(':category: :amount left', ['category' => $chip->name, 'amount' => money($chip->left())]) }}
            </span>
        </div>
    @endif

    {{-- Today's fixed lines --}}
    @foreach ($rows as $row)
        @php
            $auto = $row->state === DueState::DueToday;
            $quiet = $row->state === DueState::Overdue;
        @endphp
        <div wire:key="due-{{ $row->lineId }}" @if ($auto) x-show="! dismissed({{ $row->lineId }})" @endif
             @class(['mx-5 mt-2.5 flex flex-wrap items-center gap-3 rounded-[18px] py-2.5 pr-2.5 pl-3', 'bg-surface shadow-e1' => ! $quiet, 'shadow-[inset_0_0_0_1px_var(--app-line)]' => $quiet])
             data-test="due-row">
            <x-ui.icon-tile :icon="$row->isSavings() ? 'savings' : ($row->loanId ? 'account_balance' : 'receipt_long')" />
            <span class="flex min-w-0 flex-1 flex-col">
                <b @class(['text-[15px] leading-5', 'font-semibold' => ! $quiet, 'font-medium text-ink-2' => $quiet])>
                    @if ($auto)
                        {{ __('Goes out today: :name :amount', ['name' => $row->name, 'amount' => money($row->amount)]) }}
                    @elseif ($quiet)
                        {{ $row->isSavings() ? __('Not set aside yet: :name :amount', ['name' => $row->name, 'amount' => money($row->amount)]) : __('Not done yet: :name :amount', ['name' => $row->name, 'amount' => money($row->amount)]) }}
                    @else
                        {{ __('Due today: :name :amount', ['name' => $row->name, 'amount' => money($row->amount)]) }}
                    @endif
                </b>
                <span class="text-[13px] leading-[18px] font-medium text-muted">
                    @if ($auto)
                        {{ __('taken off in advance, nothing to do') }}
                    @elseif ($quiet)
                        {{ __('it was due :date', ['date' => \App\Support\Dates::short($row->dueOn)]) }}
                    @elseif ($row->isSavings())
                        {{ __('Only a reminder, the app does not move money.') }}
                    @else
                        {{ __('You pay it by hand. Already taken off.') }}
                    @endif
                </span>
            </span>
            @if ($auto)
                <button type="button" class="-my-1 -mr-0.5 flex size-9 items-center justify-center rounded-xl text-muted" x-on:click="dismiss({{ $row->lineId }})" aria-label="{{ __('Hide') }}">
                    <x-ui.icon name="close" :size="20" />
                </button>
            @else
                <div class="flex w-full gap-1 pl-[52px]">
                    <x-ui.button size="sm" variant="tonal" wire:click="markDone({{ $row->lineId }})" data-test="mark-done">{{ $row->isSavings() ? __('Set aside') : __('Mark as done') }}</x-ui.button>
                    @unless ($row->isSavings())
                        <x-ui.button size="sm" variant="text" x-on:click="openAmount({{ $row->lineId }}, @js(\App\Support\Money::toInput($row->amount, $currency)), @js($row->name))">{{ __('It was another amount') }}</x-ui.button>
                    @endunless
                </div>
            @endif
        </div>
    @endforeach

    <div class="min-h-6 flex-1"></div>

    <div class="flex flex-col gap-1 px-5">
        <x-ui.button size="lg" icon="add" class="w-full" x-on:click="$dispatch('open-entry')" data-record-button-twin data-test="record">{{ __('I spent') }}</x-ui.button>
        <x-no-spend-button :marked="$view->noSpendToday" class="w-full justify-center" />
    </div>

    <x-celebration :keys="$celebrate" />

    <x-ui.numpad-sheet show="amountOpen" close="amountOpen = false" title="amountTitle" done="saveAmount()" display="display('value')" :decimal="$currency->decimals() > 0" error="amountError" :intro="\Illuminate\Support\Js::from(__('Only this month. The difference changes what is left.'))->toHtml()" data-test="amount-sheet" />
</div>

@script
<script>
    Alpine.data('todayPage', ({ decimals, locale, date }) => ({
        ...window.amountFields({ decimals, locale, fields: { value: '' }, active: 'value' }),
        amountOpen: false,
        amountError: null,
        amountTitle: '',
        lineId: null,
        hidden: [],

        init() {
            try {
                this.hidden = JSON.parse(localStorage.getItem('today-hidden-' + date) ?? '[]')
            } catch (e) {
                this.hidden = []
            }
        },
        dismissed(id) {
            return this.hidden.includes(id)
        },
        dismiss(id) {
            this.hidden.push(id)
            try {
                localStorage.setItem('today-hidden-' + date, JSON.stringify(this.hidden))
            } catch (e) {
                // Private mode: hidden for this visit only.
            }
        },
        openAmount(id, value, name) {
            this.lineId = id
            this.editAmount('value', value, name)
            this.amountTitle = name
            this.amountError = null
            this.amountOpen = true
        },
        async saveAmount() {
            const result = await $wire.setAmount(this.lineId, this.fields.value || '0')
            this.amountError = result.error
            if (result.ok) this.amountOpen = false
        },
    }))
</script>
@endscript
