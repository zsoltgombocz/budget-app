<?php

use App\Actions\Budget\DeletePlanLine;
use App\Actions\Budget\ReorderCategories;
use App\Actions\Budget\SavePlanLine;
use App\Enums\CalcMode;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Livewire\Forms\PlanLineForm;
use App\Models\BudgetLine;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Services\AllocationCalculator;
use App\Services\BudgetCalculator;
use App\Services\Data\MonthEndForecast;
use App\Services\Data\PlanSummary;
use App\Services\PeriodService;
use App\Services\PlanService;
use App\Support\Dates;
use App\Support\Icons;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan')] class extends Component {
    public PlanLineForm $form;

    public bool $editing = false;

    /**
     * Data for the line editor sheet, in major units as typed on the numpad.
     *
     * @return array<string, mixed>
     */
    public function lineData(?int $lineId = null, ?string $type = null): array
    {
        $this->form->reset();

        if ($lineId !== null) {
            $line = $this->lines->firstWhere('id', $lineId);
            abort_unless($line instanceof BudgetLine, 404);
            $this->form->fillFrom($line, $this->currency);
        } else {
            $this->form->startNew(LineType::tryFrom((string) $type) ?? LineType::Variable);
            $this->form->icon = $this->form->type === LineType::Variable->value ? 'shopping_basket' : '';
        }

        return [
            'lineId' => $this->form->lineId,
            'name' => $this->form->name,
            'type' => $this->form->type,
            'icon' => Icons::forCategory($this->form->icon),
            'isQuickEntry' => $this->form->isQuickEntry,
            'calcMode' => $this->form->calcMode,
            'dueDay' => $this->form->dueDay === null ? '' : (string) $this->form->dueDay,
            'pocketId' => $this->form->pocketId,
            'loanId' => $this->form->loanId,
            'activeFrom' => $this->form->activeFrom,
            'activeTo' => $this->form->activeTo,
            'note' => $this->form->note,
            'amounts' => [
                'amount' => str_replace('.', ',', $this->form->amount === '0' ? '' : $this->form->amount),
                'amountAvg' => str_replace('.', ',', $this->form->amountAvg),
                'amountMax' => str_replace('.', ',', $this->form->amountMax),
                'dueDay' => $this->form->dueDay === null ? '' : (string) $this->form->dueDay,
            ],
        ];
    }

    /**
     * Save the editor sheet in one call. Returns validation errors instead of throwing,
     * so the sheet keeps its local state.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function saveLine(array $data, SavePlanLine $savePlanLine): array
    {
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $text = fn (string $key): string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        $number = fn (string $key): ?int => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;
        $amount = fn (string $key): string => is_scalar($amounts[$key] ?? null) ? (string) $amounts[$key] : '';

        $this->form->fill([
            'lineId' => $number('lineId'),
            'name' => $text('name'),
            'type' => $text('type'),
            'icon' => $text('icon'),
            'isQuickEntry' => (bool) ($data['isQuickEntry'] ?? false),
            'calcMode' => $text('calcMode') ?: CalcMode::Fixed->value,
            'amount' => $amount('amount') === '' ? '0' : $amount('amount'),
            'amountAvg' => $amount('amountAvg'),
            'amountMax' => $amount('amountMax'),
            'dueDay' => is_numeric($amounts['dueDay'] ?? null) ? (int) $amounts['dueDay'] : null,
            'pocketId' => $number('pocketId'),
            'loanId' => $number('loanId'),
            'activeFrom' => $text('activeFrom') ?: null,
            'activeTo' => $text('activeTo') ?: null,
            'note' => $text('note'),
        ]);

        try {
            $payload = $this->form->payload($this->currency, $this->user()->id);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => array_map(fn (array $messages): string => $messages[0], $exception->errors())];
        }

        $line = $this->form->lineId !== null ? $this->lines->firstWhere('id', $this->form->lineId) : null;
        $savePlanLine->handle($this->user(), $payload, $line instanceof BudgetLine ? $line : null);

        $this->refreshPlan();
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Plan updated.'));

        return ['ok' => true, 'errors' => []];
    }

    public function deleteLine(int $lineId, DeletePlanLine $deletePlanLine): void
    {
        $line = $this->lines->firstWhere('id', $lineId);
        abort_unless($line instanceof BudgetLine, 404);

        $deletePlanLine->handle($line);
        $this->refreshPlan();
        $this->dispatch('budget-updated');
    }

    public function setCalcMode(int $lineId, string $mode): void
    {
        $line = $this->lines->firstWhere('id', $lineId);
        $calcMode = CalcMode::tryFrom($mode);

        if (! $line instanceof BudgetLine || $calcMode === null || $line->category?->type !== LineType::Variable) {
            return;
        }

        $line->update(['calc_mode' => $calcMode]);
        $this->refreshPlan();
        $this->dispatch('budget-updated');
    }

    /**
     * Share of the month-end leftover that goes to the reserve (the leftover rule), saved at once.
     */
    public function setReservePct(int $pct): void
    {
        abort_unless(in_array($pct, [0, 25, 50, 75, 100], true), 422);

        $this->user()->settings()->update(['reserve_pct' => $pct, 'reserve_fixed' => null]);
        unset($this->monthEnd);
    }

    /**
     * Switch the leftover rule between a share ('pct') and a fixed amount ('fixed').
     */
    public function setReserveMode(string $mode): void
    {
        $settings = $this->user()->settings();

        if ($mode === 'fixed' && $settings->reserve_fixed === null) {
            $settings->update(['reserve_fixed' => 0]);
        } elseif ($mode === 'pct') {
            $settings->update(['reserve_fixed' => null]);
        }

        unset($this->monthEnd);
    }

    /**
     * The reserve sheet: monthly saving (the reserve's plan line), target and fixed leftover amount.
     *
     * @return array{exists: bool, fixedMode: bool, amounts: array{monthly: string, target: string, fixed: string}}
     */
    public function reserveData(): array
    {
        $user = $this->user();
        $reserve = $user->pockets()->where('is_reserve', true)->first();
        $monthly = $reserve === null ? null : $user->budgetLines()->where('pocket_id', $reserve->id)->value('amount');
        $fixed = $user->settings()->reserve_fixed;

        return [
            'exists' => $reserve !== null,
            'fixedMode' => $fixed !== null,
            'amounts' => [
                'monthly' => $monthly === null ? '' : str_replace('.', ',', Money::toInput((int) $monthly, $this->currency)),
                'target' => $reserve?->target_amount === null ? '' : str_replace('.', ',', Money::toInput($reserve->target_amount, $this->currency)),
                'fixed' => $fixed === null ? '' : str_replace('.', ',', Money::toInput($fixed, $this->currency)),
            ],
        ];
    }

    /**
     * Save the reserve sheet. Creates the reserve pocket when there is none yet, and keeps its
     * monthly saving as a plan line (added, updated, or left at 0).
     *
     * @param  array<string, mixed>  $amounts
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function saveReserve(array $amounts): array
    {
        $values = [];
        $errors = [];

        foreach (['monthly', 'target', 'fixed'] as $field) {
            $raw = is_scalar($amounts[$field] ?? null) ? trim((string) $amounts[$field]) : '';
            $values[$field] = $raw === '' ? null : Money::parse($raw, $this->currency);

            if ($raw !== '' && $values[$field] === null) {
                $errors[$field] = __('Enter a valid amount.');
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $user = $this->user();
        $reserve = $user->pockets()->where('is_reserve', true)->first()
            ?? $user->pockets()->create(['name' => __('Reserve'), 'is_reserve' => true, 'sort' => $user->pockets()->count() + 1]);
        $reserve->update(['target_amount' => $values['target'] ?: null]);

        $line = $user->budgetLines()->where('pocket_id', $reserve->id)->first();

        if ($line !== null) {
            $line->update(['amount' => $values['monthly'] ?? 0]);
        } elseif (($values['monthly'] ?? 0) > 0) {
            $category = $user->categories()->create([
                'name' => __('Reserve'),
                'type' => LineType::Sinking,
                'icon' => 'shield',
                'color' => 'teal',
                'sort' => $user->categories()->withTrashed()->count() + 1,
                'is_quick_entry' => false,
            ]);
            $user->budgetLines()->create(['category_id' => $category->id, 'amount' => $values['monthly'], 'calc_mode' => CalcMode::Fixed, 'pocket_id' => $reserve->id]);
        }

        if ($user->settings()->reserve_fixed !== null) {
            $user->settings()->update(['reserve_fixed' => $values['fixed'] ?? 0]);
        }

        unset($this->lines, $this->groups, $this->summary, $this->monthEnd);
        $this->dispatch('budget-updated');

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Where the rest of the leftover goes: 'account:ID', 'pocket:ID' or '' (stays on the account).
     */
    public function setSurplusTarget(string $target): void
    {
        $user = $this->user();
        [$type, $id] = array_pad(explode(':', $target, 2), 2, null);
        $accountId = $type === 'account' ? $user->accounts()->whereKey((int) $id)->value('id') : null;
        $pocketId = $type === 'pocket' ? $user->pockets()->where('is_reserve', false)->whereKey((int) $id)->value('id') : null;

        $user->settings()->update(['surplus_account_id' => $accountId, 'surplus_pocket_id' => $pocketId]);
    }

    #[Computed]
    public function monthEnd(): MonthEndForecast
    {
        $user = $this->user();
        $reserve = $user->pockets()->where('is_reserve', true)->first();
        $calculator = app(BudgetCalculator::class);

        return app(AllocationCalculator::class)->monthEnd(
            $this->summary,
            $calculator->planLines($this->lines),
            $user->settings()->reserve_pct,
            $reserve?->id,
            $reserve->balance ?? 0,
            $reserve?->target_amount,
            $user->settings()->reserve_fixed,
        );
    }

    /**
     * Leftover targets: 'account:ID' / 'pocket:ID' => name.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function surplusTargets(): array
    {
        $user = $this->user();
        $targets = ['' => __('Stays on the account')];

        foreach ($user->accounts()->orderBy('name')->get() as $account) {
            $targets['account:'.$account->id] = $account->name;
        }

        foreach ($user->pockets()->where('is_reserve', false)->orderBy('sort')->get() as $pocket) {
            $targets['pocket:'.$pocket->id] = $pocket->name;
        }

        return $targets;
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function saveIncome(string $value): array
    {
        $income = Money::parse($value, $this->currency);

        if ($income === null) {
            return ['ok' => false, 'error' => __('Enter a valid amount.')];
        }

        $this->user()->settings()->update(['income' => $income]);
        app(PeriodService::class)->current($this->user())->update(['income_planned' => $income]);
        $this->refreshPlan();
        $this->dispatch('budget-updated');

        return ['ok' => true, 'error' => null];
    }

    public function reorder(int|string $categoryId, int $position): void
    {
        app(ReorderCategories::class)->handle($this->user(), (int) $categoryId, $position);
        $this->refreshPlan();
    }

    /**
     * @return Collection<int, BudgetLine>
     */
    #[Computed]
    public function lines(): Collection
    {
        $lines = app(PlanService::class)->budgetLines($this->user());
        $lines->load(['loan', 'pocket']);

        return $lines;
    }

    /**
     * Sections in the order of the design: transfers, loans, fixed, sinking, variable.
     *
     * @return array<string, Collection<int, BudgetLine>>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ([LineType::Transfer, LineType::Loan, LineType::Fixed, LineType::Sinking, LineType::Variable] as $type) {
            $groups[$type->value] = $this->lines->filter(fn (BudgetLine $line): bool => $line->category?->type === $type)->values();
        }

        return $groups;
    }

    #[Computed]
    public function summary(): PlanSummary
    {
        $calculator = app(BudgetCalculator::class);

        return $calculator->summarize($calculator->planLines($this->lines), $this->user()->settings()->income);
    }

    #[Computed]
    public function currency(): Currency
    {
        return $this->user()->settings()->currency;
    }

    /**
     * @return Collection<int, Pocket>
     */
    #[Computed]
    public function pockets(): Collection
    {
        return $this->user()->pockets()->orderBy('sort')->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    #[Computed]
    public function loans(): Collection
    {
        return $this->user()->loans()->orderBy('name')->get();
    }

    public function sectionTitle(LineType $type): string
    {
        return match ($type) {
            LineType::Transfer => $this->user()->settings()->period_mode === PeriodMode::Payday ? __('Payday transfers') : __('Transfers'),
            LineType::Loan => __('Loan repayments'),
            LineType::Fixed => __('Fixed costs'),
            LineType::Sinking => __('Monthly saving into pockets'),
            LineType::Variable => __('Variable spending'),
        };
    }

    public function plannedAmount(BudgetLine $line): int
    {
        return match ($line->calc_mode) {
            CalcMode::Avg => $line->amount_avg ?? $line->amount,
            CalcMode::Max => $line->amount_max ?? $line->amount,
            CalcMode::Fixed => $line->amount,
        };
    }

    private function refreshPlan(): void
    {
        unset($this->lines, $this->groups, $this->summary);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $settings = auth()->user()->settings();
    $period = app(PeriodService::class)->current(auth()->user());
    $summary = $this->summary;
    $types = [LineType::Transfer, LineType::Loan, LineType::Fixed, LineType::Sinking, LineType::Variable];
    $copyNote = $settings->period_mode === PeriodMode::Payday ? __('copied on every payday') : __('copied every month');
@endphp

<div x-data="planPage({ decimals: {{ $this->currency->decimals() }}, locale: @js(str_replace('_', '-', app()->getLocale())) })">
    <x-ui.page-header :title="__('Plan')" :subtitle="Dates::range($period->starts_on, $period->ends_on).' · '.$copyNote">
        <x-slot name="actions">
            <x-ui.icon-button :icon="$editing ? 'check' : 'edit'" wire:click="$toggle('editing')" :label="$editing ? __('Done') : __('Edit')" class="{{ $editing ? '!bg-accent !text-accent-ink' : '' }}" data-test="toggle-edit" />
        </x-slot>
    </x-ui.page-header>

    <div class="sticky top-[var(--safe-top)] z-10 bg-bg px-4 pb-1 pt-3.5">
        <div class="grid grid-cols-3 gap-2 rounded-[22px] border border-ink/7 bg-surface-2 px-[18px] py-3.5" data-test="plan-summary">
            <div><div class="text-xs text-muted">{{ __('Income') }}</div><div class="num mt-[3px] text-base font-semibold">{{ money_number($summary->income) }}</div></div>
            <div><div class="text-xs text-muted">{{ __('Expenses') }}</div><div class="num mt-[3px] text-base font-semibold">{{ money_number($summary->totalExpenses) }}</div></div>
            <div class="text-right"><div class="text-xs text-muted">{{ __('Left') }}</div><div @class(['num mt-[3px] text-base font-bold', 'text-accent' => $summary->leftover >= 0, 'text-danger' => $summary->leftover < 0])>{{ money_number($summary->leftover) }}</div></div>
        </div>
    </div>

    <button type="button" x-on:click="openIncome(@js(Money::toInput($settings->income, $this->currency)))" class="mx-4 mt-3.5 flex w-[calc(100%-2rem)] items-center justify-between rounded-[22px] bg-surface px-[18px] py-4 text-left" data-test="income">
        <span class="flex items-center gap-3">
            <x-ui.icon-tile icon="payments" tone="accent" />
            <span>
                <span class="block text-[15px] font-medium">{{ __('Salary') }}</span>
                <span class="block text-xs text-muted">
                    {{ $settings->period_mode === PeriodMode::Payday ? __('payday: day :day of every month', ['day' => $settings->payday_day]) : __('calendar month') }}
                </span>
            </span>
        </span>
        <span class="num text-[17px] font-semibold text-accent">{{ money($summary->income) }}</span>
    </button>

    @foreach ($types as $type)
        @php $lines = $this->groups[$type->value]; @endphp
        {{-- The repayment section always shows, so a loan can be added from the plan. --}}
        @continue($lines->isEmpty() && ! $editing && $type !== LineType::Loan)
        <section wire:key="group-{{ $type->value }}" class="mt-[22px] px-4">
            <x-ui.section-label :label="$this->sectionTitle($type)" :aside="trans_choice('{0} no items|{1} :count item|[2,*] :count items', $lines->count(), ['count' => $lines->count()])" />
            <div class="rounded-[22px] bg-surface px-[18px] py-0.5">
                <div @if ($editing) wire:sort="reorder" @endif>
                    @foreach ($lines as $line)
                        @php
                            $hasToggle = $type === LineType::Variable && $line->amount_avg !== null && $line->amount_max !== null;
                            $subtitle = match (true) {
                                $type === LineType::Loan && $line->loan !== null && $line->loan->principal_balance > 0 => __('principal :amount', ['amount' => money($line->loan->principal_balance)]).($line->loan->remaining_months ? ' · '.trans_choice('{1} :count month left|[2,*] :count months left', $line->loan->remaining_months, ['count' => $line->loan->remaining_months]) : ''),
                                $type === LineType::Loan => __('monthly installment, tap for the loan details'),
                                $line->pocket !== null && $line->pocket->prepay_step !== null => __('into :pocket · prepay step :step', ['pocket' => $line->pocket->name, 'step' => money($line->pocket->prepay_step)]),
                                $line->pocket !== null => __('into :pocket', ['pocket' => $line->pocket->name]),
                                $line->due_day !== null => __('due on day :day', ['day' => $line->due_day]),
                                default => null,
                            };
                        @endphp
                        <div wire:key="line-{{ $line->id }}" @if ($editing) wire:sort:item="{{ $line->category_id }}" @endif
                             @class(['py-[13px]', 'border-b border-line' => true]) data-test="plan-line">
                            <div class="flex items-center gap-3">
                                @if ($editing)
                                    <span wire:sort:handle class="cursor-grab touch-none text-faint" aria-label="{{ __('Drag to reorder') }}"><x-ui.icon name="drag_indicator" :size="20" /></span>
                                @endif
                                <x-ui.icon-tile :icon="Icons::forCategory($line->category?->icon)" :size="36" data-test="line-icon" />
                                @if ($type === LineType::Loan && $line->loan_id !== null && ! $editing)
                                    {{-- A repayment follows its loan, so it opens the loan itself. --}}
                                    <a href="{{ route('pockets', ['hitel' => $line->loan_id, 'vissza' => 'terv']) }}" wire:navigate class="flex min-w-0 flex-1 items-center justify-between gap-3 text-left" data-test="open-loan-{{ $line->loan_id }}">
                                        <span class="min-w-0">
                                            <span class="block truncate text-[15px]">{{ $line->category?->name }}</span>
                                            @if ($subtitle)<span class="num mt-0.5 block truncate text-xs text-muted">{{ $subtitle }}</span>@endif
                                        </span>
                                        <span class="num shrink-0 text-[15px] font-medium">{{ money_number($this->plannedAmount($line)) }}</span>
                                    </a>
                                @else
                                    <button type="button" x-on:click="openLine({{ $line->id }})" class="flex min-w-0 flex-1 items-center justify-between gap-3 text-left">
                                        <span class="min-w-0">
                                            <span class="block truncate text-[15px]">{{ $line->category?->name }}</span>
                                            @if ($subtitle)<span class="num mt-0.5 block truncate text-xs text-muted">{{ $subtitle }}</span>@endif
                                        </span>
                                        <span class="num shrink-0 text-[15px] font-medium">{{ money_number($this->plannedAmount($line)) }}</span>
                                    </button>
                                @endif
                            </div>
                            @if ($hasToggle)
                                <div class="mt-2.5 grid grid-cols-2 gap-[3px] rounded-xl bg-bg p-[3px]">
                                    <button type="button" wire:click="setCalcMode({{ $line->id }}, 'avg')" @class(['num h-9 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $line->calc_mode === CalcMode::Avg, 'text-muted' => $line->calc_mode !== CalcMode::Avg])>{{ __('Average') }} {{ money_number($line->amount_avg) }}</button>
                                    <button type="button" wire:click="setCalcMode({{ $line->id }}, 'max')" @class(['num h-9 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $line->calc_mode === CalcMode::Max, 'text-muted' => $line->calc_mode !== CalcMode::Max])>{{ __('Max') }} {{ money_number($line->amount_max) }}</button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($type === LineType::Loan)
                    <a href="{{ route('pockets', ['hitel' => 'uj', 'vissza' => 'terv']) }}" wire:navigate class="flex w-full items-center gap-2 border-b border-line py-[13px] text-[15px] font-medium text-accent" data-test="add-loan">
                        <x-ui.icon name="add" :size="20" />{{ __('New loan') }}
                    </a>
                @elseif ($editing)
                    <button type="button" x-on:click="openNew(@js($type->value))" class="flex w-full items-center gap-2 border-b border-line py-[13px] text-[15px] font-medium text-accent" data-test="add-{{ $type->value }}">
                        <x-ui.icon name="add" :size="20" />{{ __('New item') }}
                    </button>
                @endif
                <div class="flex justify-between py-[13px]">
                    <span class="text-sm text-muted">{{ __('Subtotal') }}</span>
                    <span class="num text-[15px] font-semibold">{{ money($summary->totalFor($type)) }}</span>
                </div>
            </div>
        </section>
    @endforeach

    @php
        $monthEnd = $this->monthEnd;
        $settings = $this->user()->settings();
        $currentTarget = $settings->surplus_account_id !== null ? 'account:'.$settings->surplus_account_id : ($settings->surplus_pocket_id !== null ? 'pocket:'.$settings->surplus_pocket_id : '');
        $targetName = $this->surplusTargets[$currentTarget] ?? __('Stays on the account');
    @endphp
    @php
        $reservePocket = $this->user()->pockets()->where('is_reserve', true)->first();
        $fixedMode = $settings->reserve_fixed !== null;
    @endphp
    <section class="mt-[22px] px-4" data-test="month-end">
        <x-ui.section-label :label="__('Reserve and leftover')" />

        <div class="rounded-[22px] bg-surface px-4 py-4" data-test="reserve-card">
            <div class="flex items-start gap-3">
                <x-ui.icon-tile icon="shield" :size="40" />
                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="text-[15px] font-medium leading-snug">{{ __('Reserve pocket') }}</div>
                    <div class="num mt-0.5 text-xs text-muted">{{ $reservePocket ? __('Balance: :amount', ['amount' => money($reservePocket->balance)]) : __('A pocket for the unexpected') }}</div>
                </div>
            </div>
            @if ($reservePocket)
                <p class="mt-3 text-[13px] leading-relaxed text-muted">{{ __('Money you put aside for the unexpected. If a month ends in the red, at closing you choose whether to take the gap from here.') }}</p>
                @foreach ([
                    ['label' => __('Put aside every month'), 'value' => $monthEnd->reserveMonthly, 'show' => true, 'hint' => __('Planned like a fixed cost and moved into the pocket at closing. 0 is fine too.')],
                    ['label' => __('Target'), 'value' => $reservePocket->target_amount, 'show' => true, 'hint' => __('Until the pocket reaches it, the month-end leftover also tops it up. Empty means no limit.')],
                ] as $row)
                    <button type="button" x-on:click="openReserve()" class="mt-3 w-full rounded-[14px] bg-surface-2 px-4 py-3 text-left" data-test="reserve-row">
                        <span class="flex items-center justify-between">
                            <span class="text-[13px] text-muted">{{ $row['label'] }}</span>
                            <span class="num text-[17px] font-semibold">@if ($row['value'] === null)<span class="text-faint">–</span>@else{{ money($row['value']) }}@endif</span>
                        </span>
                        <span class="mt-1 block text-xs leading-snug text-muted">{{ $row['hint'] }}</span>
                    </button>
                @endforeach
            @else
                <p class="mt-3 text-[13px] leading-relaxed text-muted">{{ __('No reserve pocket: the whole month-end leftover goes to the target below. You can add one later under Pockets.') }}</p>
                <x-ui.button variant="secondary" size="md" icon="add" x-on:click="openReserve()" class="mt-3 w-full" data-test="create-reserve">{{ __('Create a reserve pocket') }}</x-ui.button>
            @endif
        </div>

        <div class="mb-2.5 mt-5 px-1.5 text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ __('If money is left at month end') }}</div>
        <div class="rounded-[22px] bg-surface px-4 py-4">
            @if ($monthEnd->hasReserve)
                <div class="text-[13px] text-muted">{{ __('On top of the monthly amount, this much of the leftover also goes to the reserve until it reaches the target:') }}</div>
                <div class="mt-2 grid grid-cols-2 gap-[3px] rounded-xl bg-bg p-[3px]">
                    <button type="button" wire:click="setReserveMode('pct')" @class(['h-9 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => ! $fixedMode, 'text-muted' => $fixedMode]) data-test="reserve-mode-pct">{{ __('A share') }}</button>
                    <button type="button" wire:click="setReserveMode('fixed')" @class(['h-9 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $fixedMode, 'text-muted' => ! $fixedMode]) data-test="reserve-mode-fixed">{{ __('A fixed amount') }}</button>
                </div>
                @if ($fixedMode)
                    <button type="button" x-on:click="openReserve('fixed')" class="mt-2 flex h-12 w-full items-center justify-between rounded-[14px] bg-surface-2 px-4 text-left" data-test="reserve-fixed">
                        <span class="text-[13px] text-muted">{{ __('Amount from the leftover') }}</span>
                        <span class="num text-[17px] font-semibold">{{ money($settings->reserve_fixed) }}</span>
                    </button>
                @else
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ([0, 25, 50, 100] as $pct)
                            <x-ui.choice :selected="$settings->reserve_pct === $pct" wire:click="setReservePct({{ $pct }})" class="num h-11 rounded-xl text-[15px]" data-test="reserve-pct-{{ $pct }}">{{ $pct }}%</x-ui.choice>
                        @endforeach
                    </div>
                @endif
            @endif
            <div @class(['text-[13px] text-muted', 'mt-4' => $monthEnd->hasReserve])>{{ $monthEnd->hasReserve ? __('The rest goes to:') : __('All of it goes to:') }}</div>
            <div class="mt-2 grid gap-2" data-test="surplus-target">
                @foreach ($this->surplusTargets as $value => $name)
                    <x-ui.choice :selected="$value === $currentTarget" wire:click="setSurplusTarget('{{ $value }}')" class="rounded-btn px-4 py-3 text-left" wire:key="target-{{ $value ?: 'none' }}">
                        <span class="block text-[15px] font-semibold">{{ $name }}</span>
                        <span class="mt-0.5 block text-[13px] font-normal leading-snug text-muted">{{ match (true) {
                            str_starts_with($value, 'account:') => __('At closing it is listed as a manual transfer and we remind you to move it there.'),
                            str_starts_with($value, 'pocket:') => __('At closing it is added to this pocket in the app.'),
                            default => __('Nothing is moved: the money simply stays on your account.'),
                        } }}</span>
                    </x-ui.choice>
                @endforeach
            </div>
        </div>

        <div class="mt-3 rounded-[22px] border border-accent/30 bg-accent/8 px-4 py-4" data-test="leftover-preview">
            <div class="flex items-center gap-2 text-[13px] font-semibold text-accent"><x-ui.icon name="info" :size="18" />{{ __('A month with your numbers') }}</div>
            <div class="num mt-2 text-[13px]">
                <div class="flex justify-between py-1"><span class="text-muted">{{ __('Income') }}</span><span>{{ money($monthEnd->income) }}</span></div>
                <div class="flex justify-between py-1"><span class="text-muted">{{ __('Planned costs and budgets') }}</span><span>−{{ money($monthEnd->planned) }}</span></div>
                @if ($monthEnd->hasReserve)
                    <div class="flex justify-between py-1"><span class="text-muted">{{ __('Put aside in the reserve') }}</span><span>−{{ money($monthEnd->reserveMonthly) }}</span></div>
                @endif
                <div @class(['mt-1 flex justify-between border-t border-accent/20 pt-1.5 font-semibold', 'text-danger' => $monthEnd->leftover < 0])><span>{{ __('Expected leftover') }}</span><span>{{ money($monthEnd->leftover) }}</span></div>
            </div>
            @if ($monthEnd->leftover > 0)
                <div class="mt-3 text-[13px] font-semibold">{{ __('Put aside every month') }}</div>
                <div class="num mt-1 text-[13px]">
                    @if ($monthEnd->hasReserve)
                        <div class="flex justify-between py-1"><span class="text-muted">{{ __('Reserve: :fixed fixed + :share from the leftover', ['fixed' => money($monthEnd->reserveMonthly), 'share' => money($monthEnd->allocation->toReserve)]) }}</span><span class="font-semibold">{{ money($monthEnd->toReserveTotal()) }}</span></div>
                    @endif
                    <div class="flex justify-between py-1"><span class="text-muted">{{ $targetName }}</span><span class="font-semibold">{{ money($monthEnd->allocation->toSurplus) }}</span></div>
                </div>
            @elseif ($monthEnd->leftover === 0)
                <p class="mt-2 text-[13px] leading-relaxed text-ink-2">{{ __('Your plan uses up the whole income, so nothing is left to share out.') }}</p>
            @else
                <p class="mt-2 text-[13px] leading-relaxed text-ink-2">{{ __('Your plan is :gap over the income a month.', ['gap' => money(-$monthEnd->leftover)]) }} {{ __('It is worth lowering a budget.') }}</p>
            @endif
            <p class="mt-2 text-xs leading-snug text-muted">{{ __('Planned figures. At closing the real leftover is shared out the same way, and you can adjust it there.') }}</p>
        </div>
    </section>

    @if (! $editing)
        <div class="px-4 pt-5">
            <x-ui.button variant="secondary" size="md" icon="add" class="w-full" wire:click="$set('editing', true)">{{ __('Add or reorder items') }}</x-ui.button>
        </div>
    @endif

    {{-- Line editor --}}
    <x-ui.form-sheet show="sheet === 'line'" close="sheet = null" title="lineTitle()" data-test="line-sheet">
        <x-slot:action>
            <button type="button" x-show="line.lineId" x-on:click="removeLine()" class="text-danger" aria-label="{{ __('Delete') }}"><x-ui.icon name="delete" :size="22" /></button>
        </x-slot:action>

        <x-ui.form-group :title="__('Basics')">
            <x-ui.text-row :label="__('Name')" x-model="line.name" maxlength="80" :placeholder="__('e.g. Rent')" error="form.name" data-test="line-name" />
        </x-ui.form-group>

        <x-ui.form-group :title="__('Kind')">
            <div class="p-3">
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($types as $type)
                        <button type="button" x-on:click="line.type = @js($type->value)" class="h-9 rounded-xl border px-3 text-[13px] font-medium" :class="line.type === @js($type->value) ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-3 text-ink-2'" data-test="type-{{ $type->value }}">{{ $this->sectionTitle($type) }}</button>
                    @endforeach
                </div>
                @foreach ($types as $type)
                    <p class="mt-2.5 px-0.5 text-xs leading-snug text-muted" x-show="line.type === @js($type->value)">{{ match ($type) {
                        LineType::Transfer => __('Moved to another account, e.g. the shared one.'),
                        LineType::Loan => __('The monthly installment of a loan.'),
                        LineType::Fixed => __('Same amount every month.'),
                        LineType::Sinking => __('Put aside monthly for a later goal.'),
                        LineType::Variable => __('Everyday spending you record.'),
                    } }}</p>
                @endforeach
            </div>
        </x-ui.form-group>

        <x-ui.form-group :title="__('Amount')">
            <template x-if="line.type !== 'variable'">
                <div class="divide-y divide-line">
                    <x-ui.amount-row name="amount" :label="__('Monthly amount')" fallback="0" error="form.amount" data-test="field-amount" />
                    <x-ui.amount-row name="dueDay" :label="__('Due day')" error="form.dueDay" />
                </div>
            </template>
            <template x-if="line.type === 'variable'">
                <div class="divide-y divide-line">
                    <x-ui.amount-row name="amount" :label="__('Monthly budget')" fallback="0" error="form.amount" data-test="field-amount" />
                    <x-ui.amount-row name="amountAvg" :label="__('Typical month')" error="form.amountAvg" />
                    <x-ui.amount-row name="amountMax" :label="__('Expensive month')" error="form.amountMax" />
                    <div class="p-3">
                        <div class="mb-2 px-0.5 text-[13px] text-muted">{{ __('The plan counts with') }}</div>
                        <x-ui.segmented model="line.calcMode" :options="['fixed' => __('Budget'), 'avg' => __('Typical'), 'max' => __('Expensive')]" />
                    </div>
                </div>
            </template>
        </x-ui.form-group>

        <template x-if="line.type === 'variable'">
            <x-ui.form-group>
                <x-ui.switch-row model="line.isQuickEntry" :label="__('Show on the quick entry screen')" />
            </x-ui.form-group>
        </template>

        <template x-if="line.type === 'transfer' || line.type === 'sinking'">
            <x-ui.form-group :hint="__('At closing the monthly amount goes into the pocket.')">
                <x-ui.select-row :label="__('Into pocket')" x-model.number="line.pocketId">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($this->pockets as $pocket)<option value="{{ $pocket->id }}">{{ $pocket->name }}</option>@endforeach
                </x-ui.select-row>
            </x-ui.form-group>
        </template>

        <template x-if="line.type === 'loan'">
            <x-ui.form-group :hint="__('The amount here is the monthly installment. Prepayments go into a pocket, set its step on the Pockets screen.')">
                <x-ui.select-row :label="__('Loan')" x-model.number="line.loanId">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($this->loans as $loan)<option value="{{ $loan->id }}">{{ $loan->name }}</option>@endforeach
                </x-ui.select-row>
            </x-ui.form-group>
        </template>

        <x-ui.form-group :title="__('Icon')">
            <div class="grid grid-cols-8 gap-1.5 p-2.5">
                @foreach (Icons::CATEGORY as $icon)
                    <button type="button" x-on:click="line.icon = @js($icon)" class="flex aspect-square items-center justify-center rounded-xl" :class="line.icon === @js($icon) ? 'bg-accent/14 text-accent ring-1 ring-accent' : 'bg-surface-3 text-ink-2'" aria-label="{{ $icon }}">
                        <x-ui.icon :name="$icon" :size="20" />
                    </button>
                @endforeach
            </div>
        </x-ui.form-group>

        <x-ui.form-group :title="__('Active period')" :hint="__('Optional.')">
            <label class="flex min-h-[52px] items-center justify-between gap-3 px-4"><span class="text-[15px]">{{ __('Active from') }}</span><input type="date" x-model="line.activeFrom" class="bg-transparent text-right text-[15px] text-ink-2 outline-none"></label>
            <label class="flex min-h-[52px] items-center justify-between gap-3 px-4"><span class="text-[15px]">{{ __('Active until') }}</span><input type="date" x-model="line.activeTo" class="bg-transparent text-right text-[15px] text-ink-2 outline-none"></label>
        </x-ui.form-group>
        <p class="px-1.5 pt-2 text-xs text-danger" x-show="errors['form.activeTo']" x-text="errors['form.activeTo']"></p>

        <x-slot:footer>
            <x-ui.button x-on:click="saveLine()" ::disabled="saving" class="w-full" data-test="save-line">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$this->currency->decimals() > 0" /></x-slot:pad>
    </x-ui.form-sheet>

    {{-- Income editor --}}
    <x-ui.form-sheet show="sheet === 'income'" close="sheet = null" :label="__('Monthly net income')" :full="false" data-test="income-sheet">
        <x-slot:intro>{{ __('Monthly, after tax.') }}</x-slot:intro>
        <div class="num py-3 text-center text-[48px] font-semibold tracking-[-0.04em]" x-text="display('income')"></div>
        <div class="mb-2 text-center text-xs text-danger" x-show="incomeError" x-text="incomeError"></div>
        <x-ui.numpad :decimal="$this->currency->decimals() > 0" />
        <x-slot:footer>
            <x-ui.button x-on:click="saveIncome()" class="w-full" data-test="save-income">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.form-sheet>

    {{-- Reserve: monthly saving, target, fixed share of the leftover --}}
    <x-ui.form-sheet show="sheet === 'reserve'" close="sheet = null" :label="__('Reserve pocket')" data-test="reserve-sheet">
        <x-ui.form-group>
            <x-ui.amount-row name="monthly" :label="__('Put aside every month')" fallback="0" error="monthly" data-test="reserve-monthly" />
            <x-ui.amount-row name="target" :label="__('Target')" :hint="__('Empty means no limit')" error="target" data-test="reserve-target" />
            <template x-if="reserveFixedMode">
                <x-ui.amount-row name="fixed" :label="__('Amount from the leftover')" fallback="0" error="fixed" data-test="reserve-fixed-amount" />
            </template>
        </x-ui.form-group>
        <x-slot:footer>
            <x-ui.button x-on:click="saveReserve()" ::disabled="saving" class="w-full" data-test="save-reserve">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$this->currency->decimals() > 0" /></x-slot:pad>
    </x-ui.form-sheet>
</div>

@script
<script>
    Alpine.data('planPage', ({ decimals, locale }) => ({
        sheet: null,
        line: {},
        errors: {},
        saving: false,
        incomeError: null,
        fields: {},
        active: null,
        activeLabel: '',
        decimals,
        formatter: new Intl.NumberFormat(locale, { maximumFractionDigits: decimals, useGrouping: 'always' }),

        init() {
            this.$watch('sheet', open => document.documentElement.classList.toggle('overflow-hidden', open !== null))
        },

        // Numpad (same behaviour as the amountFields helper).
        focus(name, label = '') { this.active = name; this.activeLabel = label },
        press(key) {
            if (! this.active) return
            let value = String(this.fields[this.active] ?? '')
            const integerOnly = this.active === 'dueDay'
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (! integerOnly && this.decimals > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (! integerOnly && value && ! value.includes(',')) value += '000' }
            else {
                const fraction = value.split(',')[1]
                if (fraction !== undefined && fraction.length >= this.decimals) return
                if (value === '0') value = ''
                value += key
                if (integerOnly && parseInt(value, 10) > 31) value = key
            }
            this.fields[this.active] = value.slice(0, 12)
        },
        display(name, fallback = '0') {
            const value = String(this.fields[name] ?? '')
            if (value === '') return fallback
            const [whole, fraction] = value.split(',')
            const formatted = this.formatter.format(parseInt(whole || '0', 10))
            return fraction !== undefined ? formatted + ',' + fraction : formatted
        },
        filled(name) { return String(this.fields[name] ?? '') !== '' },

        lineTitle() { return this.line.lineId ? @js(__('Edit item')) : @js(__('New item')) },

        async openLine(id) {
            this.load(await $wire.lineData(id))
        },
        async openNew(type) {
            this.load(await $wire.lineData(null, type))
        },
        load(data) {
            this.line = data
            this.fields = { ...data.amounts }
            this.active = null
            this.errors = {}
            this.sheet = 'line'
        },
        async saveLine() {
            this.saving = true
            try {
                const result = await $wire.saveLine({ ...this.line, amounts: { ...this.fields } })
                this.errors = result.errors ?? {}
                if (result.ok) this.sheet = null
            } finally {
                this.saving = false
            }
        },
        async removeLine() {
            if (! await window.appConfirm({ title: @js(__('Remove this line from the plan?')), body: @js(__('Past spending keeps its category; only the plan changes from now on.')), confirm: @js(__('Remove')), danger: true })) return
            await $wire.deleteLine(this.line.lineId)
            this.sheet = null
        },

        openIncome(value) {
            this.fields = { income: String(value).replace('.', ',') }
            this.active = 'income'
            this.incomeError = null
            this.sheet = 'income'
        },
        async saveIncome() {
            const result = await $wire.saveIncome(this.fields.income || '0')
            this.incomeError = result.error
            if (result.ok) this.sheet = null
        },
        reserveFixedMode: false,
        async openReserve(focusField = null) {
            const data = await $wire.reserveData()
            this.fields = { ...data.amounts }
            this.reserveFixedMode = data.fixedMode || focusField === 'fixed'
            this.errors = {}
            this.active = null
            this.sheet = 'reserve'
            if (focusField) this.focus(focusField, @js(__('Amount from the leftover')))
        },
        async saveReserve() {
            this.saving = true
            try {
                const result = await $wire.saveReserve({ ...this.fields })
                this.errors = result.errors ?? {}
                if (result.ok) this.sheet = null
            } finally {
                this.saving = false
            }
        },
    }))
</script>
@endscript
