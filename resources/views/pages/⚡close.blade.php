<?php

use App\Actions\Month\CloseMonth;
use App\Actions\Month\MarkLineDone;
use App\Enums\CloseDecision;
use App\Models\Period;
use App\Models\User;
use App\Services\Data\CloseSummary;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Support\Dates;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The 2.0 closing (S13): one screen, one decision about the leftover (or a real deficit).
 * The numbers come from CloseMonth::summary(); nothing moves until "Kész".
 */
new #[Title('Close the month')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    #[Locked]
    public int $periodId;

    /** What came in this month when it differs from the plan ("Más jött?"); null keeps the planned income. */
    public ?int $incomeActual = null;

    public ?string $decision = null;

    public ?int $goalId = null;

    /** With "Félreteszem": how much goes to the goal; the rest is carried over (the split). Null puts all of it there. */
    public ?int $toGoal = null;

    public function mount(int $period): void
    {
        $this->periodId = $this->user()->periods()->findOrFail($period)->id;
        $this->goalId = $this->user()->pockets()->goals()->orderBy('sort')->value('id');
        $this->decision = $this->summary->decisions($this->goalId !== null)[0]->value;
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function setIncome(string $value): array
    {
        $amount = Money::parse($value, $this->user()->settings()->currency);

        if ($amount === null) {
            return ['ok' => false, 'error' => __('Enter a valid amount.')];
        }

        $this->incomeActual = $amount === $this->period->income_planned ? null : $amount;
        $this->settle();

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function setToGoal(string $value): array
    {
        $amount = Money::parse($value, $this->user()->settings()->currency);
        $leftover = $this->summary->leftover();

        if ($amount === null || $amount > $leftover) {
            return ['ok' => false, 'error' => __('At most :amount.', ['amount' => money($leftover)])];
        }

        $this->toGoal = $amount;
        $this->decision = CloseDecision::Save->value;

        return ['ok' => true, 'error' => null];
    }

    /**
     * "A Nyaralás 45 000 Ft-ját félretetted?" – yes: the goal grows now (MarkLineDone).
     */
    public function confirmSavings(int $budgetLineId): void
    {
        resolve(MarkLineDone::class)->mark($this->user(), $this->period, $budgetLineId);
        unset($this->summary);
    }

    public function close(CloseMonth $closeMonth): void
    {
        $decision = CloseDecision::tryFrom((string) $this->decision);

        if (! $decision instanceof CloseDecision) {
            $this->addError('decision', __('Choose what happens to the leftover.'));

            return;
        }

        try {
            $close = $closeMonth->handle($this->user(), $this->period, $decision, $this->goalId, $this->toGoal, $this->incomeActual);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $next = $this->user()->periods()->whereDate('starts_on', '>', $this->period->starts_on->toDateString())->oldest('starts_on')->first();

        $this->dispatch('app-toast',
            title: __('Done, here comes :month', ['month' => \App\Support\Hungarian::withArticle(mb_strtolower(Dates::monthName(($next ?? $this->period)->nameDate())))]),
            subtitle: __('You can undo it until the next closing.'),
            undo: 'undo-close',
            params: ['periodId' => $close->period_id],
        );
        $this->redirectRoute('dashboard', navigate: true);
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function summary(): CloseSummary
    {
        return resolve(CloseMonth::class)->summary($this->user(), $this->period, $this->incomeActual);
    }

    /**
     * @return array{spent: int, top: string|null, days: int}
     */
    #[Computed]
    public function stats(): array
    {
        $period = $this->period;
        $top = $period->transactions()->whereNull('pocket_id')->selectRaw('category_id, sum(amount) as total')->groupBy('category_id')->orderByDesc('total')->first();
        $days = $period->transactions()->distinct()->pluck('occurred_on')->map(fn ($date): string => (string) $date)
            ->merge($this->user()->dayMarks()->whereBetween('date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->pluck('date')->map(fn ($date): string => (string) $date))
            ->unique()->count();

        return [
            'spent' => $this->summary->spent,
            'top' => $top !== null ? $this->user()->categories()->withTrashed()->find($top->category_id)?->name : null,
            'days' => $days,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Pocket>
     */
    #[Computed]
    public function goals(): \Illuminate\Support\Collection
    {
        return $this->user()->pockets()->goals()->orderBy('sort')->orderBy('name')->get()->toBase();
    }

    /**
     * The chosen decision must fit the leftover after the income changed sign.
     */
    private function settle(): void
    {
        unset($this->summary, $this->stats);
        $decisions = array_map(fn (CloseDecision $decision): string => $decision->value, $this->summary->decisions($this->goalId !== null));

        if (! in_array($this->decision, $decisions, true)) {
            $this->decision = $decisions[0];
        }

        if ($this->toGoal !== null && $this->toGoal > $this->summary->leftover()) {
            $this->toGoal = null;
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $period = $this->period;
    $summary = $this->summary;
    $leftover = $summary->leftover();
    $currency = user_currency();
    $closer = resolve(PeriodCloser::class);
    $today = resolve(PeriodService::class)->today(auth()->user()->settings());
    $month = Dates::monthName($period->nameDate());
    $next = $period->ends_on->addDay();
    $nextMonth = mb_strtolower(Dates::monthName($next));
    $goals = $this->goals;
    $goal = $goals->firstWhere('id', $this->goalId);
    $closesEarly = $today->lessThan($period->ends_on);
    $options = [
        'save' => [__('Set it aside'), $goal ? __('The :goal goal grows by :amount.', ['goal' => \Illuminate\Support\Str::ucfirst(\App\Support\Hungarian::withArticle($goal->name)), 'amount' => money($this->toGoal ?? $leftover)]).($this->toGoal !== null && $this->toGoal < $leftover ? ' '.__(':amount goes into :month.', ['amount' => money($leftover - $this->toGoal), 'month' => Dates::monthCase($next, 'into')]) : '') : ''],
        'carry' => [__('Take it into :month', ['month' => Dates::monthCase($next, 'into')]), __(':month gets :amount more to spend.', ['month' => \Illuminate\Support\Str::ucfirst(Dates::monthCase($next, 'in')), 'amount' => money($leftover)])],
        'note' => [__('Just note it'), __('I won’t count with it any further.')],
        'deduct' => [__('Take it off :month', ['month' => Dates::monthCase($next, 'from')]), __(':month gets :amount less to spend.', ['month' => \Illuminate\Support\Str::ucfirst(Dates::monthCase($next, 'in')), 'amount' => money(-$leftover)])],
        'covered' => [__('I covered it from elsewhere'), __('E.g. from savings. Next month is not affected.')],
        'from_goal' => [__('Cover it from a goal'), $goal ? __('The :goal goal goes down by :amount.', ['goal' => \Illuminate\Support\Str::ucfirst(\App\Support\Hungarian::withArticle($goal->name)), 'amount' => money(-$leftover)]) : ''],
    ];
@endphp

<div class="flex min-h-[calc(100dvh-var(--safe-top))] flex-col px-5 pb-[calc(20px+env(safe-area-inset-bottom))]" x-data="closePage({ decimals: @js($currency->decimals()), locale: @js(app()->getLocale()) })" data-test="close-page">
    <div class="-mx-3 flex h-11 items-center">
        <x-ui.icon-button icon="chevron_left" :label="__('Back to the month')" :href="route('month', ['periodus' => $period->id])" wire:navigate />
        <h1 class="text-[17px] font-semibold">{{ __('Closing :month', ['month' => $month]) }}</h1>
    </div>

    @if (! $period->isOpen())
        <x-ui.empty-state icon="task_alt" :title="__(':month is closed', ['month' => $month])" class="mt-10">
            <x-slot:actions>
                <x-ui.button size="md" variant="secondary" :href="route('month', ['periodus' => $period->id])" wire:navigate>{{ __('Open the month') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
        <livewire:reopen-closing :period-id="$period->id" variant="text" :key="'reopen-'.$period->id" />
    @elseif (! $closer->canClose(auth()->user(), $period))
        <x-ui.empty-state icon="schedule" :title="__('Not yet')" class="mt-10" data-test="close-not-yet">
            {{ __('You can close the month from :date.', ['date' => Dates::short($closer->closableFrom($period))]) }}
        </x-ui.empty-state>
    @else
        @if ($closesEarly)
            <div class="mt-1 flex items-center gap-2.5 rounded-card bg-warn-tint px-3.5 py-3 text-sm font-medium" data-test="close-early">
                <x-ui.icon name="info" :size="20" class="text-warn-text" />{{ __('Today becomes the month’s last day, from tomorrow a new month starts.') }}
            </div>
        @endif

        <dl class="num mt-2 text-[13px] font-medium text-muted">
            <div class="flex h-7 items-center justify-between"><dt>{{ __('Income') }}: <span class="text-ink-2">{{ money($summary->income) }}</span></dt><dd><button type="button" class="font-semibold text-accent-strong" x-on:click="editIncome(@js(Money::toInput($summary->income, $currency)))" data-test="edit-income">{{ __('Did something else come in?') }}</button></dd></div>
            @if ($summary->extraIncome > 0)<div class="flex h-7 items-center"><dt>{{ __('Extra income') }}: <span class="text-ink-2">+{{ money($summary->extraIncome) }}</span></dt></div>@endif
            @if ($summary->carriedOver !== 0)<div class="flex h-7 items-center"><dt>{{ $summary->carriedOver > 0 ? __('Carried over') : __('Deducted from last month') }}: <span class="text-ink-2">{{ money($summary->carriedOver) }}</span></dt></div>@endif
            <div class="flex h-7 items-center"><dt>{{ __('Fixed expenses') }}: <span class="text-ink-2">{{ money($summary->fixedTotal - $summary->savingsTotal) }}</span></dt></div>
            @if ($summary->savingsTotal > 0)<div class="flex h-7 items-center"><dt>{{ __('Savings line') }}: <span class="text-ink-2">{{ money($summary->savingsTotal) }}</span></dt></div>@endif
        </dl>

        @foreach ($summary->unconfirmedSavings as $savings)
            <div class="mt-2 flex items-center gap-2 rounded-card bg-surface py-2.5 pr-2 pl-3.5 shadow-e1" x-data="{ hidden: false }" x-show="! hidden" wire:key="confirm-{{ $savings->lineId }}" data-test="confirm-savings">
                <span class="flex-1 text-[13px] font-medium">{{ __('Did you set aside the :amount for :goal?', ['amount' => money($savings->amount), 'goal' => \Illuminate\Support\Str::ucfirst(\App\Support\Hungarian::withArticle($goals->firstWhere('id', $savings->goalId)->name ?? $savings->name))]) }}</span>
                <x-ui.button size="sm" variant="tonal" wire:click="confirmSavings({{ $savings->lineId }})">{{ __('Yes') }}</x-ui.button>
                <x-ui.button size="sm" variant="text" x-on:click="hidden = true">{{ __('No') }}</x-ui.button>
            </div>
        @endforeach

        <div class="mt-3 flex justify-center"><x-morzsa :mood="$leftover >= 0 ? 'unneplo' : 'elgondolkodo'" :size="$leftover >= 0 ? 80 : 100" /></div>
        @if ($leftover >= 0)
            <h2 class="mt-2 text-center font-display text-[32px] leading-9 font-bold tracking-[-0.02em] text-balance" data-test="close-leftover">
                {{ \Illuminate\Support\Str::ucfirst(Dates::monthCase($period->nameDate(), 'in')) }}<br><span class="num font-sans text-[40px] font-semibold tracking-[-0.03em] text-accent-strong">{{ money($leftover) }}</span> {{ __('was left.') }}
            </h2>
        @else
            <p class="num mt-2 text-center text-[40px] leading-[44px] font-semibold tracking-[-0.03em]" data-test="close-deficit">{{ '−'.money(-$leftover) }}</p>
            <h2 class="mt-1 text-center font-display text-[26px] leading-[31px] font-bold text-balance">{{ __('In :month you spent :amount more than there was.', ['month' => \Illuminate\Support\Str::ucfirst(Dates::monthCase($period->nameDate(), 'in')), 'amount' => money(-$leftover)]) }}</h2>
            <p class="mt-2 text-center text-[15px] text-ink-2">{{ __('Months like this happen. What shall we do with it?') }}</p>
        @endif

        @if ($leftover >= 0)
            <div class="mt-3 grid grid-cols-3 gap-2" data-test="close-stats">
                <div class="min-w-0 rounded-2xl bg-surface px-2.5 py-2 shadow-e1"><b class="num block truncate text-[15px] font-semibold">{{ money($this->stats['spent']) }}</b><span class="text-xs text-muted">{{ __('spent') }}</span></div>
                <div class="min-w-0 rounded-2xl bg-surface px-2.5 py-2 shadow-e1"><b class="block truncate text-[15px] font-semibold">{{ $this->stats['top'] ?? '–' }}</b><span class="text-xs text-muted">{{ __('most went on') }}</span></div>
                <div class="min-w-0 rounded-2xl bg-surface px-2.5 py-2 shadow-e1"><b class="num block truncate text-[15px] font-semibold">{{ trans_choice('{1} :count day|[2,*] :count days', $this->stats['days'], ['count' => $this->stats['days']]) }}</b><span class="text-xs text-muted">{{ __('you wrote in') }}</span></div>
            </div>
        @endif

        <h2 class="mt-5 text-xl font-semibold">{{ $leftover >= 0 ? __('What should happen to it?') : __('What shall we do?') }}</h2>
        <div class="mt-2 flex flex-col gap-2" role="radiogroup" aria-label="{{ __('What should happen to it?') }}">
            @foreach ($summary->decisions($goals->isNotEmpty()) as $option)
                @php [$title, $subtitle] = $options[$option->value]; @endphp
                <button type="button" role="radio" aria-checked="{{ $decision === $option->value ? 'true' : 'false' }}" wire:click="$set('decision', '{{ $option->value }}')"
                        @class(['flex min-h-[68px] w-full items-center gap-3 rounded-[20px] px-3.5 py-2.5 text-left', 'border-2 border-accent bg-accent-tint' => $decision === $option->value, 'border border-control-border bg-surface' => $decision !== $option->value]) data-test="decision-{{ $option->value }}">
                    <span class="flex min-w-0 flex-col">
                        <span class="text-[15px] font-semibold">{{ $title }}</span>
                        @if ($subtitle)<span class="text-[13px] leading-[18px] font-medium text-muted">{{ $subtitle }}</span>@endif
                    </span>
                    <span @class(['ml-auto flex size-6 shrink-0 items-center justify-center rounded-full', 'bg-accent text-accent-ink' => $decision === $option->value, 'border-2 border-control-border' => $decision !== $option->value])>
                        @if ($decision === $option->value)<x-ui.icon name="check" :size="16" />@endif
                    </span>
                </button>
            @endforeach
        </div>

        @if (in_array($decision, ['save', 'from_goal'], true) && $goals->count() > 1)
            <x-ui.form-group class="mt-3">
                <x-ui.select-row :label="__('Goal')" wire:model.live="goalId" data-test="close-goal">
                    @foreach ($goals as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </x-ui.select-row>
            </x-ui.form-group>
        @endif

        @if ($leftover > 0 && $goals->isNotEmpty())
            <button type="button" class="mx-auto mt-1 flex min-h-11 items-center px-3 text-[15px] font-medium text-ink-2" x-on:click="editSplit(@js(Money::toInput($this->toGoal ?? $leftover, $currency)))" data-test="split">{{ __('Split it in two') }}</button>
        @endif
        @if ($leftover > 0)
            <p class="text-center text-[13px] text-muted">{{ __('Your money is in your bank: if you set it aside, move it there when it suits you.') }}</p>
        @endif

        @error('decision')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        @error('period')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror

        <div class="min-h-6 flex-1"></div>
        <x-ui.button wire:click="close" class="mt-4 w-full" data-test="close-done">{{ $closesEarly ? __('Close it, :month starts tomorrow', ['month' => $nextMonth]) : __('Done, here comes :month', ['month' => \App\Support\Hungarian::withArticle($nextMonth)]) }}</x-ui.button>

        <x-ui.numpad-sheet show="editor !== null" close="editor = null" title="editorTitle" done="save()" display="display('value')" :decimal="$currency->decimals() > 0" error="editorError" data-test="close-editor" />
    @endif
</div>

@script
<script>
    Alpine.data('closePage', ({ decimals, locale }) => ({
        ...window.amountFields({ decimals, locale, fields: { value: '' }, active: 'value' }),
        editor: null,
        editorTitle: '',
        editorError: null,

        editIncome(value) {
            this.open('income', value, @js(__('This much came in this month')))
        },
        editSplit(value) {
            this.open('split', value, @js(__('This much goes to the goal')))
        },
        open(editor, value, title) {
            this.editor = editor
            this.editAmount('value', value, title)
            this.editorTitle = title
            this.editorError = null
        },
        async save() {
            const value = this.fields.value || '0'
            const result = this.editor === 'income' ? await $wire.setIncome(value) : await $wire.setToGoal(value)
            this.editorError = result.error
            if (result.ok) this.editor = null
        },
    }))
</script>
@endscript
