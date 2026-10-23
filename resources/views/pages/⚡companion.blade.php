<?php

use App\Actions\Month\SaveGoal;
use App\Models\Challenge;
use App\Models\Pocket;
use App\Models\User;
use App\Services\Data\StreakState;
use App\Services\Goals;
use App\Services\PeriodService;
use App\Services\Rewards;
use App\Services\Streak;
use App\Support\Money;
use App\Support\Wardrobe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Morzsa (S14): the room that fills up with use, acorns, the streak, the weekly challenge, the goals,
 * the milestones and Morzsa's things. Acorns come from the habit only and buy nothing real.
 */
new #[Title('Morzsa')] class extends Component {
    /** @var list<string> milestones to celebrate on this visit */
    public array $celebrate = [];

    public function mount(): void
    {
        resolve(Rewards::class)->sync($this->user(), $this->today());
        $this->celebrate = resolve(Rewards::class)->takeCelebrations($this->user());
    }

    public function acceptChallenge(): void
    {
        $this->challenge->update(['accepted_at' => now()]);
        resolve(Rewards::class)->sync($this->user(), $this->today());
        unset($this->challenge, $this->acorns);
    }

    public function declineChallenge(): void
    {
        $this->challenge->update(['declined_at' => now()]);
        unset($this->challenge);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function createGoal(array $form): array
    {
        $currency = $this->user()->settings()->currency;
        $amount = fn (string $key): ?int => is_string($form[$key] ?? null) && $form[$key] !== '' ? Money::parse($form[$key], $currency) : null;

        try {
            resolve(SaveGoal::class)->create($this->user(), [
                'name' => is_string($form['name'] ?? null) ? $form['name'] : '',
                'target' => $amount('target'),
                'target_month' => is_string($form['month'] ?? null) && $form['month'] !== '' ? $form['month'] : null,
                'monthly' => $amount('monthly') ?? 0,
                'starting' => $amount('starting') ?? 0,
            ]);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => array_map(fn (array $messages): string => $messages[0] ?? '', $exception->errors())];
        }

        $this->changed();
        $this->dispatch('app-toast', title: __('Goal saved'), subtitle: __('On its day I remind you; a tap on “Félretettem” grows the goal.'));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function updateGoal(int $goalId, array $form): array
    {
        $goal = $this->user()->pockets()->findOrFail($goalId);
        $currency = $this->user()->settings()->currency;
        $target = is_string($form['target'] ?? null) && $form['target'] !== '' ? Money::parse($form['target'], $currency) : null;
        $balance = is_string($form['balance'] ?? null) && $form['balance'] !== '' ? Money::parse($form['balance'], $currency) : $goal->balance;

        try {
            resolve(SaveGoal::class)->update($this->user(), $goal, [
                'name' => is_string($form['name'] ?? null) ? $form['name'] : '',
                'target' => $target,
                'target_month' => is_string($form['month'] ?? null) && $form['month'] !== '' ? $form['month'] : null,
            ]);
            resolve(SaveGoal::class)->setBalance($this->user(), $goal->refresh(), $balance ?? $goal->balance);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => array_map(fn (array $messages): string => $messages[0] ?? '', $exception->errors())];
        }

        $this->changed();

        return ['ok' => true, 'errors' => []];
    }

    public function archiveGoal(int $goalId): void
    {
        $goal = $this->user()->pockets()->findOrFail($goalId);
        resolve(SaveGoal::class)->archive($this->user(), $goal);
        $this->changed();
        $this->dispatch('app-toast', title: __(':goal archived', ['goal' => $goal->name]), icon: 'delete', undo: 'companion-restore-goal', params: ['goalId' => $goalId]);
    }

    #[On('companion-restore-goal')]
    public function restoreGoal(int $goalId): void
    {
        resolve(SaveGoal::class)->restore($this->user(), $goalId);
        $this->changed();
    }

    public function buy(string $item): void
    {
        try {
            resolve(Rewards::class)->buy($this->user(), $item);
        } catch (ValidationException $exception) {
            $this->dispatch('app-toast', title: (string) collect($exception->errors())->flatten()->first(), icon: 'error');

            return;
        }

        unset($this->acorns, $this->owned);
        $this->dispatch('app-toast', title: __(':item is Morzsa’s now', ['item' => Wardrobe::find($item)['name'] ?? '']));
    }

    public function toggleItem(string $item): void
    {
        resolve(Rewards::class)->toggle($this->user(), $item);
        unset($this->owned);
    }

    #[Computed]
    public function acorns(): int
    {
        return resolve(Rewards::class)->balance($this->user());
    }

    #[Computed]
    public function streak(): StreakState
    {
        return resolve(Streak::class)->stateForUser($this->user(), $this->today());
    }

    #[Computed]
    public function challenge(): Challenge
    {
        return resolve(Rewards::class)->challenge($this->user(), $this->today());
    }

    #[Computed]
    public function challengeDays(): int
    {
        return resolve(Rewards::class)->daysThisWeek(resolve(Streak::class)->activeDates($this->user(), $this->today(), 8), $this->today());
    }

    /**
     * @return list<array{goal: Pocket, monthly: int, forecast: \App\Services\Data\GoalForecast|null}>
     */
    #[Computed]
    public function goals(): array
    {
        $monthly = $this->user()->budgetLines()->whereNotNull('pocket_id')->whereNull('active_to')->get()->groupBy('pocket_id')->map(fn ($lines): int => (int) $lines->sum('amount'));

        return $this->user()->pockets()->goals()->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Pocket $goal): array => [
                'goal' => $goal,
                'monthly' => $monthly[$goal->id] ?? 0,
                'forecast' => $goal->target_amount !== null ? resolve(Goals::class)->forecast($goal->target_amount, $goal->balance, $monthly[$goal->id] ?? 0, $this->today()) : null,
            ])->values()->all();
    }

    /**
     * @return array<string, bool> owned item => equipped
     */
    #[Computed]
    public function owned(): array
    {
        return $this->user()->items()->pluck('equipped', 'item')->map(fn ($equipped): bool => (bool) $equipped)->all();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function achieved(): array
    {
        return $this->user()->achievements()->pluck('key')->all();
    }

    #[Computed]
    public function stage(): string
    {
        return $this->user()->periodCloses()->exists() || $this->streak->longest >= 30 ? 'kucko' : 'doboz';
    }

    private function changed(): void
    {
        unset($this->goals, $this->acorns, $this->achieved);
        resolve(Rewards::class)->sync($this->user(), $this->today());
        $this->celebrate = [...$this->celebrate, ...resolve(Rewards::class)->takeCelebrations($this->user())];
        $this->dispatch('budget-updated');
    }

    private function today(): CarbonImmutable
    {
        return resolve(PeriodService::class)->today($this->user()->settings());
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $currency = user_currency();
    $off = auth()->user()->settings()->companion_mode === 'off';
    $streak = $this->streak;
    $challenge = $this->challenge;
    $hour = (int) now()->setTimezone(auth()->user()->settings()->timezone)->format('G');
    $time = $hour >= 6 && $hour < 17 ? 'reggel' : ($hour >= 17 && $hour < 22 ? 'este' : 'ejjel');
    $goals = $this->goals;
    $jars = array_map(fn ($row) => $row['goal']->target_amount ? $row['goal']->balance / $row['goal']->target_amount : 0.5, $goals);
    $equipped = array_keys(array_filter($this->owned));
    $milestones = \App\Services\Rewards::milestones();
    $monthLabel = fn (?CarbonImmutable $month): string => $month ? $month->locale(app()->getLocale())->isoFormat('YYYY. MMMM') : __('no deadline');
    $daysLeftInWeek = (int) now()->setTimezone(auth()->user()->settings()->timezone)->startOfDay()->diffInDays(now()->endOfWeek()->startOfDay());
@endphp

<div class="px-5 pt-4" x-data="companionPage({ decimals: @js($currency->decimals()), locale: @js(app()->getLocale()), thisMonth: @js(now()->format('Y-m')) })">
    <div class="flex items-center justify-between">
        <h1 class="font-display text-[32px] leading-9 font-bold tracking-[-0.02em]">{{ $off ? __('Progress') : __('Morzsa') }}</h1>
        <span class="num inline-flex h-8 items-center gap-1.5 rounded-full bg-plum-tint pr-3 pl-2 text-[15px] font-semibold text-plum" data-test="acorns">
            <svg class="size-5" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10.5h12c0 5.8-3 9.6-6 10.2-3-.6-6-4.4-6-10.2z" fill="#E9C38F"/><path d="M4.6 11c0-3.8 3.4-6 7.4-6s7.4 2.2 7.4 6z" fill="#8A5A34"/></svg>
            {{ trans_choice('{1} :count acorn|[2,*] :count acorns', $this->acorns, ['count' => $this->acorns]) }}
        </span>
    </div>

    @unless ($off)
        <x-morzsa-room :stage="$this->stage" :time="$time" :jars="$jars" :items="$equipped" class="mt-3" />
    @endunless

    {{-- Streak --}}
    <div class="mt-3 flex items-center gap-3 rounded-card bg-surface px-4 py-3 shadow-e1" data-test="streak-card">
        <svg class="size-9 shrink-0" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10.5h12c0 5.8-3 9.6-6 10.2-3-.6-6-4.4-6-10.2z" fill="#E9C38F"/><path d="M4.6 11c0-3.8 3.4-6 7.4-6s7.4 2.2 7.4 6z" fill="#8A5A34"/><path d="M12 .8c2 1.6 2.6 3 1.6 4.6h-3.2c-1-1.6-.4-3 1.6-4.6z" fill="#E3A462"/></svg>
        <div class="min-w-0 flex-1">
            <div class="text-[13px] font-semibold text-muted">{{ __('Streak') }}</div>
            <div class="num text-[22px] leading-7 font-semibold">{{ trans_choice('{0} Not yet|{1} :count day|[2,*] :count days', $streak->current, ['count' => $streak->current]) }}</div>
            <div class="text-[13px] text-muted">
                {{ __('Longest: :count', ['count' => $streak->longest]) }}@if ($streak->restAvailable) · {{ __('you have 1 rest day') }}@endif
            </div>
        </div>
    </div>

    {{-- Weekly challenge (optional) --}}
    @if ($challenge->declined_at === null)
        <div class="mt-3 rounded-card bg-surface px-4 py-3 shadow-e1" data-test="challenge">
            @if ($challenge->completed_at)
                <div class="text-[15px] font-semibold">{{ __('Weekly challenge done') }}</div>
                <div class="text-[13px] text-muted">{{ __('Done. +:count acorns', ['count' => \App\Services\Rewards::CHALLENGE]) }} · {{ __(':count days written in this week.', ['count' => $this->challengeDays]) }}</div>
            @elseif ($challenge->accepted_at)
                <div class="flex items-baseline justify-between"><span class="text-[15px] font-semibold">{{ __('Weekly challenge') }}</span><span class="num text-[13px] text-muted">{{ min($this->challengeDays, $challenge->target) }}/{{ $challenge->target }}</span></div>
                <x-ui.bar :label="__('Weekly challenge')" :value="min(1, $this->challengeDays / $challenge->target)" tone="accent" class="mt-2" />
                <div class="mt-1.5 text-[13px] text-muted">{{ __('Write in on :count days of the week.', ['count' => $challenge->target]) }} · {{ trans_choice('{0} last day|{1} :count more day · until Sunday|[2,*] :count more days · until Sunday', $daysLeftInWeek, ['count' => $daysLeftInWeek]) }}</div>
            @else
                <div class="flex items-baseline justify-between"><span class="text-[15px] font-semibold">{{ __('Weekly challenge') }}</span><span class="text-[13px] font-semibold text-plum">+{{ \App\Services\Rewards::CHALLENGE }} {{ __('acorns') }}</span></div>
                <div class="mt-0.5 text-[15px] text-ink-2">{{ __('Write in on :count days of the week.', ['count' => $challenge->target]) }}</div>
                <div class="mt-2 flex gap-1">
                    <x-ui.button size="sm" variant="tonal" wire:click="acceptChallenge" data-test="accept-challenge">{{ __('I’m in') }}</x-ui.button>
                    <x-ui.button size="sm" variant="text" wire:click="declineChallenge">{{ __('Not now') }}</x-ui.button>
                </div>
            @endif
        </div>
    @endif

    {{-- Goals --}}
    <h2 class="px-1.5 pt-6 pb-2 text-[13px] font-semibold text-ink-2">{{ __('Goals') }}</h2>
    @forelse ($goals as $row)
        @php
            $goal = $row['goal'];
            $reached = $goal->target_amount !== null && $goal->balance >= $goal->target_amount;
        @endphp
        <button type="button" wire:key="goal-{{ $goal->id }}" class="focus-ring mb-2 w-full rounded-card bg-surface px-4 py-3 text-left shadow-e1"
                x-on:click="openGoal(@js(['id' => $goal->id, 'name' => $goal->name, 'target' => $goal->target_amount ? Money::toInput($goal->target_amount, $currency) : '', 'balance' => Money::toInput($goal->balance, $currency), 'month' => $goal->target_month?->format('Y-m') ?? '', 'reached' => $reached]))" data-test="goal">
            <div class="flex items-baseline justify-between gap-2">
                <span class="truncate text-[15px] font-semibold">{{ $goal->name }}</span>
                @if ($reached)<span class="rounded-lg bg-plum-tint px-2 py-0.5 text-xs font-semibold text-plum">{{ __('Reached') }}</span>@endif
            </div>
            <div class="text-[13px] text-muted">{{ $row['monthly'] > 0 ? __(':amount a month', ['amount' => money($row['monthly'])]) : __('no monthly amount') }} · {{ $monthLabel($goal->target_month) }}</div>
            <div class="num mt-2 flex items-baseline gap-1.5"><span class="text-[22px] font-semibold">{{ money_number($goal->balance) }}</span>@if ($goal->target_amount)<span class="text-[13px] text-muted">/ {{ money($goal->target_amount) }}</span>@else<span class="text-[13px] text-muted">{{ $currency->symbol() }}</span>@endif</div>
            @if ($goal->target_amount)
                <x-ui.bar :label="$goal->name" :value="min(1, $goal->balance / $goal->target_amount)" tone="accent" class="mt-2" />
                @if (! $reached && $row['forecast']?->reachedIn)
                    <div class="mt-1.5 text-xs text-muted">{{ __('At this pace it is there by :month.', ['month' => $row['forecast']->reachedIn->locale(app()->getLocale())->isoFormat('YYYY. MMMM')]) }}</div>
                @endif
            @endif
        </button>
    @empty
        <div class="flex items-center gap-3 rounded-card bg-surface px-4 py-3 shadow-e1">
            <x-morzsa mood="kivancsi" :size="56" pouch />
            <div class="flex-1"><div class="text-[15px] font-semibold">{{ __('Saving up for something?') }}</div><div class="text-[13px] text-muted">{{ __('I help, and you see where you are.') }}</div></div>
        </div>
    @endforelse
    <x-ui.button variant="secondary" size="md" icon="add" class="mt-2 w-full" x-on:click="newGoal()" data-test="new-goal">{{ __('New goal') }}</x-ui.button>

    {{-- Milestones --}}
    <h2 class="px-1.5 pt-6 pb-2 text-[13px] font-semibold text-ink-2">{{ __('Milestones') }}</h2>
    <div class="grid grid-cols-4 gap-2" data-test="milestones">
        @foreach ($milestones as $key => $milestone)
            @php $got = in_array($key, $this->achieved, true); @endphp
            <div @class(['flex flex-col items-center gap-1 rounded-2xl px-1 py-2 text-center', 'bg-plum-tint' => $got, 'bg-surface-2 opacity-60' => ! $got]) title="{{ $milestone['text'] }}">
                <svg class="size-9" viewBox="0 0 72 72" aria-hidden="true"><path d="M36 6 L62 21 L62 51 L36 66 L10 51 L10 21 Z" @class(['fill-plum-tint stroke-plum' => $got, 'fill-none stroke-control-border' => ! $got]) stroke-width="4" stroke-linejoin="round"/></svg>
                <span class="text-[11px] leading-tight font-semibold">{{ $milestone['title'] }}</span>
            </div>
        @endforeach
    </div>
    <p class="mt-2 px-1.5 text-xs text-muted">{{ __(':got of :all reached.', ['got' => count($this->achieved), 'all' => \App\Support\Hungarian::withSuffix(count($milestones), 'ból', 'ből')]) }}</p>

    {{-- Wardrobe --}}
    <h2 class="px-1.5 pt-6 pb-2 text-[13px] font-semibold text-ink-2">{{ __('Wardrobe') }}</h2>
    <x-ui.card class="px-4 py-1" data-test="wardrobe">
        @foreach (Wardrobe::items() as $key => $item)
            <div @class(['flex min-h-14 items-center gap-3 py-2', 'border-b border-line' => ! $loop->last]) wire:key="item-{{ $key }}">
                <span class="flex-1 text-[15px]">{{ $item['name'] }}</span>
                @if (array_key_exists($key, $this->owned))
                    <x-ui.button size="sm" :variant="$this->owned[$key] ? 'tonal' : 'secondary'" wire:click="toggleItem('{{ $key }}')">{{ $this->owned[$key] ? __('Worn') : __('Put on') }}</x-ui.button>
                @else
                    <x-ui.button size="sm" variant="secondary" wire:click="buy('{{ $key }}')" :disabled="$this->acorns < $item['price']" data-test="buy-{{ $key }}">{{ trans_choice('{1} :count acorn|[2,*] :count acorns', $item['price'], ['count' => $item['price']]) }}</x-ui.button>
                @endif
            </div>
        @endforeach
    </x-ui.card>
    <p class="mt-2 px-1.5 pb-4 text-xs text-muted">{{ __('Acorns come from writing in, streaks and challenges. They cannot be bought.') }}</p>

    {{-- New goal --}}
    <x-ui.sheet show="sheet === 'new'" close="sheet = null" :label="__('New goal')" data-test="goal-sheet">
        <p class="pb-2 text-[13px] font-semibold">{{ __('What are you saving for?') }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach ([__('Emergency fund'), __('Holiday'), __('Present'), __('Car')] as $template)
                <x-ui.choice x-on:click="form.name = {{ \Illuminate\Support\Js::from($template) }}" ::selected="form.name === {{ \Illuminate\Support\Js::from($template) }}" ::aria-pressed="form.name === {{ \Illuminate\Support\Js::from($template) }}" class="rounded-control px-3 text-[15px]">{{ $template }}</x-ui.choice>
            @endforeach
        </div>
        <x-ui.form-group class="mt-3">
            <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="60" data-test="goal-name" />
            <x-ui.amount-row name="target" :label="__('How much do you need?')" fallback="{{ __('Not sure yet') }}" />
            <label class="relative flex min-h-[52px] items-center justify-between gap-3 px-4 py-2.5">
                <span class="text-[15px]">{{ __('By when?') }}</span>
                <span class="num text-[15px] text-ink-2" x-text="form.month ? monthLabel(form.month) : @js(__('No deadline'))"></span>
                <input type="month" x-model="form.month" :min="thisMonth" class="absolute inset-0 opacity-0" aria-label="{{ __('By when?') }}" data-test="goal-month">
            </label>
            <x-ui.amount-row name="starting" :label="__('Anything on it already?')" fallback="0" />
            <x-ui.amount-row name="monthly" :label="__('Monthly')" fallback="0" />
        </x-ui.form-group>
        <p class="px-1.5 pt-2 text-[13px] text-muted" x-show="suggestion() > 0" x-text="suggestionText()"></p>
        <p class="px-1.5 pt-2 text-xs text-muted">{{ __('The monthly amount becomes a fixed line (Félretétel), so the month’s money is that much smaller. On its day I remind you; the goal grows on “Félretettem”.') }}</p>
        <p class="px-1.5 pt-2 text-xs text-danger" x-show="error" x-text="error"></p>
        <x-slot:footer>
            <x-ui.button x-on:click="saveNew()" class="w-full" data-test="save-goal">{{ __('Save the goal') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>

    {{-- A goal --}}
    <x-ui.sheet show="sheet === 'edit'" close="sheet = null" title="form.name" data-test="goal-edit-sheet">
        <template x-if="form.reached">
            <div class="mb-3 flex items-center gap-3 rounded-card bg-plum-tint px-3 py-3">
                <x-morzsa mood="unneplo" :size="56" pouch />
                <div class="flex-1"><div class="font-display text-xl font-bold">{{ __('Goal reached.') }}</div><div class="text-[13px] text-ink-2">{{ __('What should happen to the goal?') }}</div></div>
            </div>
        </template>
        <x-ui.form-group>
            <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="60" />
            <x-ui.amount-row name="target" :label="__('Target')" fallback="{{ __('none') }}" />
            <label class="relative flex min-h-[52px] items-center justify-between gap-3 px-4 py-2.5">
                <span class="text-[15px]">{{ __('By when?') }}</span>
                <span class="num text-[15px] text-ink-2" x-text="form.month ? monthLabel(form.month) : @js(__('No deadline'))"></span>
                <input type="month" x-model="form.month" class="absolute inset-0 opacity-0" aria-label="{{ __('By when?') }}">
            </label>
            <x-ui.amount-row name="balance" :label="__('On it now')" :hint="__('Set it to what is really there.')" />
        </x-ui.form-group>
        <p class="px-1.5 pt-2 text-xs text-danger" x-show="error" x-text="error"></p>
        <x-slot:danger>
            <x-ui.button variant="danger" size="md" icon="delete" x-on:click="archive()" class="w-full" data-test="archive-goal" x-text="form.reached ? {{ \Illuminate\Support\Js::from(__('Done, archive it')) }} : {{ \Illuminate\Support\Js::from(__('Archive the goal')) }}">{{ __('Archive the goal') }}</x-ui.button>
        </x-slot:danger>
        <x-slot:footer>
            <x-ui.button x-on:click="saveEdit()" class="w-full">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>

    <x-celebration :keys="$celebrate" />
</div>

@script
<script>
    Alpine.data('companionPage', ({ decimals, locale, thisMonth }) => ({
        ...window.amountFields({ decimals, locale, fields: { target: '', starting: '', monthly: '', balance: '' }, rules: { target: { maxWhole: 9 }, starting: { maxWhole: 9 }, monthly: { maxWhole: 9 }, balance: { maxWhole: 9 } } }),
        sheet: null,
        form: { id: null, name: '', month: '', reached: false },
        error: null,
        thisMonth,
        monthlyTouched: false,

        init() {
            this.$watch('fields.monthly', (value, old) => { if (this.active === 'monthly') this.monthlyTouched = true })
            this.$watch('fields.target', () => this.suggest())
            this.$watch('fields.starting', () => this.suggest())
            this.$watch('form.month', () => this.suggest())
        },
        number(name) {
            return parseFloat(String(this.fields[name] || '0').replace(',', '.')) || 0
        },
        /** What reaches the goal by the deadline, rounded up to a thousand (Goals::monthlyFor). */
        suggestion() {
            const missing = this.number('target') - this.number('starting')
            if (! this.form.month || missing <= 0) return 0
            const [y, m] = this.form.month.split('-').map(Number)
            const [ty, tm] = thisMonth.split('-').map(Number)
            const months = Math.max(1, (y - ty) * 12 + (m - tm) + 1)
            return Math.ceil(missing / months / 1000) * 1000
        },
        suggest() {
            if (this.sheet === 'new' && ! this.monthlyTouched && this.suggestion() > 0) this.fields.monthly = String(this.suggestion())
        },
        suggestionText() {
            return @js(__('With :amount a month it is there by the deadline.')).replace(':amount', new Intl.NumberFormat(locale).format(this.suggestion()) + ' ' + @js($currency->symbol()))
        },
        monthLabel(value) {
            return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'long' }).format(new Date(value + '-01T12:00:00'))
        },
        newGoal() {
            this.form = { id: null, name: '', month: '', reached: false }
            this.fields = { ...this.fields, target: '', starting: '', monthly: '', balance: '' }
            this.monthlyTouched = false
            this.error = null
            this.active = null
            this.sheet = 'new'
        },
        openGoal(goal) {
            this.form = { id: goal.id, name: goal.name, month: goal.month, reached: goal.reached }
            this.fields = { ...this.fields, target: goal.target, balance: goal.balance }
            this.error = null
            this.active = null
            this.sheet = 'edit'
        },
        async saveNew() {
            this.active = null
            const result = await $wire.createGoal({ name: this.form.name, month: this.form.month, target: this.fields.target, starting: this.fields.starting, monthly: this.fields.monthly })
            this.error = Object.values(result.errors ?? {}).join(' ') || null
            if (result.ok) this.sheet = null
        },
        async saveEdit() {
            this.active = null
            const result = await $wire.updateGoal(this.form.id, { name: this.form.name, month: this.form.month, target: this.fields.target, balance: this.fields.balance })
            this.error = Object.values(result.errors ?? {}).join(' ') || null
            if (result.ok) this.sheet = null
        },
        async archive() {
            const id = this.form.id
            this.sheet = null
            await $wire.archiveGoal(id)
        },
    }))
</script>
@endscript
