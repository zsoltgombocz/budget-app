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
use App\Services\PlanChangeImpact;
use App\Services\PlanService;
use App\Support\Dates;
use App\Support\Icons;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan')] class extends Component {
    public PlanLineForm $form;

    public bool $editing = false;

    /** Read out after a line moves (aria-live), e.g. "Utilities is now 2nd of 4". */
    public string $reorderStatus = '';

    /** Counts leftover rule changes; each one shows a short "Saved" status by the rule sentence. */
    public int $ruleSavedTick = 0;

    /**
     * Data for the line editor sheet, in major units as typed on the numpad.
     *
     * @return array<string, mixed>
     */
    public function lineData(?int $lineId = null, ?string $type = null): array
    {
        $this->form->reset();

        $deleteNote = '';

        if ($lineId !== null) {
            $line = $this->lines->firstWhere('id', $lineId);
            abort_unless($line instanceof BudgetLine, 404);
            $this->form->fillFrom($line, $this->currency);
            $from = app(PlanChangeImpact::class)->appliesFrom($this->user(), $line);
            $deleteNote = $from instanceof CarbonImmutable
                ? __('Recorded spending stays, with its category. The item also leaves the plan of the current period (:from); closed months do not change.', ['from' => Dates::from($from)])
                : __('Recorded spending stays, with its category. Closed months do not change.');
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
            'deleteNote' => $deleteNote,
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
     * so the sheet keeps its local state. A type change that recounts spending already
     * recorded in the open period is not saved until `typeChangeConfirmed` comes with it:
     * the answer then carries the question to ask.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, errors: array<string, string>, confirm?: array{title: string, body: string, confirm: string}}
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
        $line = $line instanceof BudgetLine ? $line : null;

        if ($line instanceof BudgetLine && ! ($data['typeChangeConfirmed'] ?? false)) {
            $question = $this->typeChangeQuestion($line, $payload['type']);

            if ($question !== null) {
                return ['ok' => false, 'errors' => [], 'confirm' => $question];
            }
        }

        $this->planChanged($savePlanLine->handle($this->user(), $payload, $line));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * The confirmation for a type change that recounts spending of the open period, if any.
     *
     * @return array{title: string, body: string, confirm: string}|null
     */
    private function typeChangeQuestion(BudgetLine $line, LineType $newType): ?array
    {
        $impact = app(PlanChangeImpact::class);
        $spent = $impact->spendingRecountedBy($this->user(), $line, $newType);

        if ($spent === null) {
            return null;
        }

        $from = $impact->appliesFrom($this->user());
        $body = $newType->isTracked()
            ? __('This period already has :amount of spending recorded here. As variable spending, closing counts that recorded spending instead of the planned amount, and the expected leftover on Today takes off the larger of the budget and the spending.', ['amount' => money($spent)])
            : __('This period already has :amount of spending recorded here. As “:type”, both Today and the closing count the planned amount instead of that spending.', ['amount' => money($spent), 'type' => $this->sectionTitle($newType)]);

        return [
            'title' => __('Change the kind of this item?'),
            'body' => $from instanceof CarbonImmutable ? $body.' '.__('The change also applies to the current period (:from).', ['from' => Dates::from($from)]) : $body,
            'confirm' => __('Change kind'),
        ];
    }

    public function deleteLine(int $lineId, DeletePlanLine $deletePlanLine): void
    {
        $line = $this->lines->firstWhere('id', $lineId);
        abort_unless($line instanceof BudgetLine, 404);

        $deletePlanLine->handle($line);
        $this->planChanged($line, __('Item removed from the plan.'));
    }

    public function setCalcMode(int $lineId, string $mode): void
    {
        $line = $this->lines->firstWhere('id', $lineId);
        $calcMode = CalcMode::tryFrom($mode);

        if (! $line instanceof BudgetLine || $calcMode === null || $line->category?->type !== LineType::Variable) {
            return;
        }

        $line->update(['calc_mode' => $calcMode]);

        // A one-tap choice shows itself as selected, so it saves without a toast.
        $this->syncPlan();
    }

    /**
     * Share of the month-end leftover that goes to the reserve (the leftover rule), saved at once.
     */
    public function setReservePct(int $pct): void
    {
        abort_unless(in_array($pct, [0, 25, 50, 75, 100], true), 422);

        $this->user()->settings()->update(['reserve_pct' => $pct, 'reserve_fixed' => null]);
        unset($this->monthEnd);
        $this->ruleSaved();
    }

    /**
     * Feedback after a leftover rule choice, inline next to the rule sentence (no toast: the
     * choice shows itself as selected and saves at once).
     */
    private function ruleSaved(): void
    {
        $this->ruleSavedTick++;
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
        $this->ruleSaved();
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

        $monthlyChanged = false;

        if ($line !== null) {
            $line->update(['amount' => $values['monthly'] ?? 0]);
            $monthlyChanged = $line->wasChanged('amount');
        } elseif (($values['monthly'] ?? 0) > 0) {
            $monthlyChanged = true;
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

        // Only the monthly saving is a plan line; the target and the fixed amount are rules for closing.
        if ($monthlyChanged) {
            $this->planChanged();
        } else {
            $this->syncPlan();
        }

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
        unset($this->monthEnd);
        $this->ruleSaved();
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
        $this->planChanged();

        return ['ok' => true, 'error' => null];
    }

    public function reorder(int|string $categoryId, int $position): void
    {
        app(ReorderCategories::class)->handle($this->user(), (int) $categoryId, $position);
        $this->refreshPlan();
        $this->reordered((int) $categoryId);
    }

    /**
     * Move a line one place up (-1) or down (1) within its group: reordering without dragging.
     */
    public function move(int $categoryId, int $direction): void
    {
        $line = $this->lines->firstWhere('category_id', $categoryId);
        abort_unless($line instanceof BudgetLine && $line->category !== null, 404);

        $siblings = $this->groups[$line->category->type->value]->pluck('category_id')->values();
        $index = (int) $siblings->search($categoryId);
        $position = $index + ($direction < 0 ? -1 : 1);

        if ($position < 0 || $position >= $siblings->count()) {
            return;
        }

        $this->reorder($categoryId, $position);
    }

    private function reordered(int $categoryId): void
    {
        $line = $this->lines->firstWhere('category_id', $categoryId);

        if (! $line instanceof BudgetLine || $line->category === null) {
            return;
        }

        $siblings = $this->groups[$line->category->type->value]->pluck('category_id')->values();
        $this->reorderStatus = __(':name is now in place :position of :count.', ['name' => $line->category->name, 'position' => (int) $siblings->search($categoryId) + 1, 'count' => $siblings->count()]);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Order saved.'));
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
            LineType::Fixed => __('Fixed items'),
            LineType::Sinking => __('Sinking funds'),
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

    /**
     * First day of the open current period, which counts with the live plan from that day on;
     * null when the current period is closed.
     */
    #[Computed]
    public function scopeFrom(): ?CarbonImmutable
    {
        return app(PlanChangeImpact::class)->appliesFrom($this->user());
    }

    /**
     * The leftover rule in one sentence, e.g. "50% to the reserve up to a target of 300 000 Ft,
     * the rest stays on the account". Follows AllocationCalculator::allocate().
     */
    public function leftoverRule(): string
    {
        $settings = $this->user()->settings();
        $targets = $this->surplusTargets;
        $targetKey = $settings->surplus_account_id !== null ? 'account:'.$settings->surplus_account_id : ($settings->surplus_pocket_id !== null ? 'pocket:'.$settings->surplus_pocket_id : '');
        $targetName = $targetKey !== '' ? ($targets[$targetKey] ?? null) : null;
        $reserve = $this->reservePocket;
        $share = match (true) {
            ! $reserve instanceof Pocket => null,
            $settings->reserve_fixed !== null => $settings->reserve_fixed > 0 ? __('up to :amount', ['amount' => money($settings->reserve_fixed)]) : null,
            default => $settings->reserve_pct > 0 ? $settings->reserve_pct.'%' : null,
        };

        if ($share === null) {
            return $targetName === null ? __('All of it stays on the account') : __('All of it goes to :name', ['name' => $targetName]);
        }

        $target = $reserve?->target_amount;
        $toReserve = $target === null
            ? __(':share to the reserve', ['share' => $share])
            : __(':share to the reserve up to a target of :target', ['share' => $share, 'target' => money($target)]);

        // The whole leftover with no cap: nothing is ever left for the other target.
        if ($settings->reserve_fixed === null && $settings->reserve_pct === 100 && $target === null) {
            return Str::ucfirst($toReserve);
        }

        return Str::ucfirst($toReserve).', '.($targetName === null ? __('the rest stays on the account') : __('the rest goes to :name', ['name' => $targetName]));
    }

    #[Computed]
    public function reservePocket(): ?Pocket
    {
        return $this->user()->pockets()->where('is_reserve', true)->first();
    }

    /**
     * After a change to the plan: recompute, tell the other components, and say in the toast
     * that the open period counts with it too (it reads the live plan from its first day).
     * The line narrows that to a period the line is active in.
     */
    private function planChanged(?BudgetLine $line = null, ?string $title = null): void
    {
        $this->syncPlan();

        $from = app(PlanChangeImpact::class)->appliesFrom($this->user(), $line);

        $this->dispatch(
            'app-toast',
            title: $title ?? __('Plan updated.'),
            subtitle: $from instanceof CarbonImmutable ? __('Also applies to the current period (:from).', ['from' => Dates::from($from)]) : null,
        );
    }

    /**
     * Recompute and tell the other components, without a toast.
     */
    private function syncPlan(): void
    {
        $this->refreshPlan();
        $this->dispatch('budget-updated');
    }

    private function refreshPlan(): void
    {
        unset($this->lines, $this->groups, $this->summary, $this->monthEnd, $this->reservePocket);
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
    $summary = $this->summary;
    $types = [LineType::Transfer, LineType::Loan, LineType::Fixed, LineType::Sinking, LineType::Variable];
    $scopeFrom = $this->scopeFrom;
    $scopeNote = $scopeFrom ? __('The change also applies to the current period (:from).', ['from' => Dates::from($scopeFrom)]) : null;
@endphp

@php
    // ?tetel=ID opens that line's editor (links from Today and Month, e.g. to set a budget).
    $openLineId = is_numeric(request()->query('tetel')) && $this->lines->contains('id', (int) request()->query('tetel')) ? (int) request()->query('tetel') : null;
@endphp
<div x-data="planPage({ decimals: {{ $this->currency->decimals() }}, locale: @js(str_replace('_', '-', app()->getLocale())), openLineId: @js($openLineId) })" @if ($editing) data-hides-record-button @endif>
    <p class="sr-only" role="status" aria-live="polite" data-test="reorder-status">{{ $reorderStatus }}</p>
    {{-- Edit mode is a visible state: the title changes and a Done button leaves it. --}}
    <x-ui.page-header :title="$editing ? __('Edit') : __('Plan')" :subtitle="$editing ? __('Drag by the handle or use the arrows.') : __('Every month starts with this plan.')" data-test="plan-header">
        <x-slot name="actions">
            @if ($editing)
                <x-ui.button variant="tonal" size="sm" wire:click="$set('editing', false)" data-test="toggle-edit">{{ __('Done') }}</x-ui.button>
            @else
                <x-ui.icon-button icon="edit" wire:click="$set('editing', true)" :label="__('Add or reorder items')" data-test="toggle-edit" />
            @endif
        </x-slot>
    </x-ui.page-header>

    {{--
        Bevétel / Kiadás / Marad stays on top while scrolling: a band over the page, not a floating card.
        Opaque enough that the list never shows through the labels (solid where backdrop blur is missing),
        with a hairline under it once it is stuck.
    --}}
    {{-- Where the band would sit unstuck: once this marker scrolls above the band's sticky top, the band is stuck. --}}
    <div x-ref="summaryMarker" aria-hidden="true"></div>
    <div x-data="{ stuck: false, check() { this.stuck = this.$refs.summaryMarker.getBoundingClientRect().top < parseFloat(getComputedStyle(this.$el).top) } }"
         x-init="check()" x-on:scroll.window.passive="check()"
         class="sticky top-[var(--safe-top)] z-10 border-b border-transparent bg-bg px-6 pb-3 pt-4 transition-colors max-[359px]:px-5 data-stuck:border-line supports-[backdrop-filter:blur(1px)]:bg-bg/95 supports-[backdrop-filter:blur(1px)]:backdrop-blur-xl"
         :data-stuck="stuck ? 'true' : null" data-test="plan-summary-band">
        {{-- Three figures side by side; on a very narrow screen with long amounts they wrap instead of overflowing. --}}
        <div class="flex flex-wrap justify-between gap-x-3 gap-y-2" data-test="plan-summary">
            <div class="min-w-0"><div class="text-xs text-muted">{{ __('Income') }}</div><x-ui.amount size="sm" :value="$summary->income" class="mt-[3px] text-base font-semibold max-[359px]:text-[15px]" x-data x-number-change /></div>
            <div class="min-w-0"><div class="text-xs text-muted">{{ __('Expenses') }}</div><x-ui.amount size="sm" :value="$summary->totalExpenses" class="mt-[3px] text-base font-semibold max-[359px]:text-[15px]" x-data x-number-change /></div>
            {{-- Green only when something is really left; zero stays neutral. --}}
            <div class="ml-auto min-w-0 text-right"><div class="text-xs text-muted">{{ __('Left') }}</div><x-ui.amount size="sm" :value="$summary->leftover" :tone="match (true) { $summary->leftover > 0 => 'accent', $summary->leftover < 0 => 'danger', default => null }" class="mt-[3px] text-base font-semibold max-[359px]:text-[15px]" x-data x-number-change data-test="plan-left" /></div>
        </div>
    </div>

    <button type="button" x-on:click="openIncome(@js(Money::toInput($settings->income, $this->currency)))" class="focus-ring mx-4 mt-1 flex w-[calc(100%-2rem)] items-center justify-between gap-3 rounded-card bg-surface px-[18px] py-4 text-left" data-test="income">
        <span class="flex min-w-0 items-center gap-3">
            <x-ui.icon-tile icon="payments" tone="accent" />
            <span class="min-w-0">
                <span class="block text-[15px] font-medium">{{ __('Salary') }}</span>
                {{-- One line also at 320 px: the short form drops "of every month" there. --}}
                <span class="block truncate text-[13px] text-muted" data-test="income-detail">
                    @if ($settings->period_mode === PeriodMode::Payday)
                        <span class="max-[359px]:hidden">{{ __('payday: day :day of every month', ['day' => $settings->payday_day]) }}</span><span class="min-[360px]:hidden">{{ __('payday: day :day', ['day' => $settings->payday_day]) }}</span>
                    @else
                        {{ __('calendar month') }}
                    @endif
                </span>
            </span>
        </span>
        <x-ui.amount size="sm" :value="$summary->income" tone="accent" class="shrink-0 text-[17px] font-semibold max-[359px]:text-[15px]" />
    </button>

    @foreach ($types as $type)
        @php $lines = $this->groups[$type->value]; @endphp
        {{-- The repayment section always shows, so a loan can be added from the plan. --}}
        @continue($lines->isEmpty() && ! $editing && $type !== LineType::Loan)
        <section wire:key="group-{{ $type->value }}" class="mt-[22px] px-4" data-test="group-{{ $type->value }}">
            <x-ui.section-label group :label="$this->sectionTitle($type)" :aside="trans_choice('{0} no items|{1} :count item|[2,*] :count items', $lines->count(), ['count' => $lines->count()])" />
            <div class="divide-y divide-line rounded-card bg-surface px-[18px] py-0.5">
                @if ($lines->isNotEmpty())
                    <div class="divide-y divide-line" @if ($editing) wire:sort="reorder" @endif>
                        @foreach ($lines as $line)
                            @php
                                $hasToggle = $type === LineType::Variable && $line->amount_avg !== null && $line->amount_max !== null;
                                // A repayment line is named after its loan.
                                $name = (string) ($type === LineType::Loan && $line->loan !== null ? $line->loan->name : $line->category?->name);
                                // One short line under the name, only where it says something the name does not.
                                $detail = match (true) {
                                    $type === LineType::Loan && $line->loan !== null && $line->loan->principal_balance > 0 => __('principal :amount', ['amount' => money($line->loan->principal_balance)]).($line->loan->remaining_months ? ' · '.trans_choice('{1} :count month left|[2,*] :count months left', $line->loan->remaining_months, ['count' => $line->loan->remaining_months]) : ''),
                                    $line->pocket !== null && mb_strtolower($line->pocket->name) !== mb_strtolower($name) => __('into :pocket', ['pocket' => $line->pocket->name]),
                                    default => null,
                                };
                                // A repayment follows its loan, so outside edit mode it opens the loan itself.
                                $opensLoan = $type === LineType::Loan && $line->loan_id !== null && ! $editing;
                            @endphp
                            <div wire:key="line-{{ $line->id }}" @if ($editing) wire:sort:item="{{ $line->category_id }}" @endif data-test="plan-line">
                                <div class="flex items-center">
                                    @if ($editing)
                                        <span wire:sort:handle class="-ml-3 flex size-11 shrink-0 cursor-grab touch-none items-center justify-center text-muted" role="img" aria-label="{{ __('Drag to reorder') }}" data-test="line-handle"><x-ui.icon name="drag_indicator" :size="20" /></span>
                                        {{-- Reordering without dragging: one place up or down, for keyboards and screen readers. Stacked in one 32 px column. --}}
                                        <span class="mr-2 flex w-8 shrink-0 flex-col">
                                            @foreach ([[-1, 'keyboard_arrow_up', __('Move up: :name', ['name' => $name])], [1, 'keyboard_arrow_down', __('Move down: :name', ['name' => $name])]] as [$direction, $arrow, $moveLabel])
                                                <button type="button" x-on:click="moveLine({{ $line->category_id }}, {{ $direction }})" aria-label="{{ $moveLabel }}"
                                                        @disabled(($direction < 0 && $loop->parent->first) || ($direction > 0 && $loop->parent->last))
                                                        class="focus-ring flex h-6 w-8 items-center justify-center rounded-small text-ink-2 disabled:text-ink/15" data-move="{{ $line->category_id }}:{{ $direction }}" data-test="move-{{ $direction < 0 ? 'up' : 'down' }}"><x-ui.icon :name="$arrow" :size="20" /></button>
                                            @endforeach
                                        </span>
                                    @endif
                                    <{{ $opensLoan ? 'a' : 'button' }}
                                        @if ($opensLoan) href="{{ route('pockets', ['hitel' => $line->loan_id, 'vissza' => 'terv']) }}" wire:navigate data-test="open-loan-{{ $line->loan_id }}"
                                        @else type="button" x-on:click="openLine({{ $line->id }})" data-test="open-line" @endif
                                        class="block min-w-0 flex-1 py-2.5 text-left">
                                        {{--
                                            In edit mode the tile steps aside for the handle and arrows, and the amount drops under a long name instead of cutting it short.
                                            In normal mode on a 320 px phone a long name takes a second line.
                                        --}}
                                        <span @class(['flex items-center gap-x-3', 'flex-wrap gap-y-0.5' => $editing])>
                                            @unless ($editing)
                                                <x-ui.icon-tile :icon="Icons::forCategory($line->category?->icon)" :size="36" data-test="line-icon" />
                                            @endunless
                                            <span @class(['min-w-0 truncate text-[15px]', 'flex-1 max-[359.98px]:line-clamp-2 max-[359.98px]:whitespace-normal' => ! $editing, 'grow' => $editing]) data-test="line-name">{{ $name }}</span>
                                            <span class="ml-auto flex shrink-0 items-center gap-3">
                                                @if ($line->due_day !== null)
                                                    {{-- Below 360px the badge leaves its room to the name: screen readers still hear the day. --}}
                                                    <span class="num inline-flex h-[22px] items-center rounded-full bg-surface-3 px-2 text-xs font-semibold text-muted max-[359.98px]:sr-only" data-test="due-day">
                                                        <span aria-hidden="true">{{ $line->due_day }}.</span><span class="sr-only">{{ __('due on day :day', ['day' => $line->due_day]) }}</span>
                                                    </span>
                                                @endif
                                                <x-ui.amount size="sm" :value="$this->plannedAmount($line)" class="text-[15px] font-medium" />
                                            </span>
                                        </span>
                                        @if ($detail)<span @class(['num mt-0.5 block text-xs leading-snug text-muted', 'pl-12' => ! $editing]) data-test="line-detail">{{ $detail }}</span>@endif
                                    </{{ $opensLoan ? 'a' : 'button' }}>
                                </div>
                                @if ($hasToggle)
                                    <div class="mb-2.5 grid grid-cols-2 gap-[3px] rounded-control bg-bg p-[3px]">
                                        <button type="button" wire:click="setCalcMode({{ $line->id }}, 'avg')" aria-pressed="{{ $line->calc_mode === CalcMode::Avg ? 'true' : 'false' }}" @class(['focus-ring num h-11 rounded-[10px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $line->calc_mode === CalcMode::Avg, 'text-muted' => $line->calc_mode !== CalcMode::Avg])>{{ __('Average') }} {{ money_number($line->amount_avg) }}</button>
                                        <button type="button" wire:click="setCalcMode({{ $line->id }}, 'max')" aria-pressed="{{ $line->calc_mode === CalcMode::Max ? 'true' : 'false' }}" @class(['focus-ring num h-11 rounded-[10px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $line->calc_mode === CalcMode::Max, 'text-muted' => $line->calc_mode !== CalcMode::Max])>{{ __('Max') }} {{ money_number($line->amount_max) }}</button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($type === LineType::Loan)
                    <x-ui.link :href="route('pockets', ['hitel' => 'uj', 'vissza' => 'terv'])" wire:navigate icon="add" class="w-full py-[13px] text-[15px]" data-test="add-loan">{{ __('New loan') }}</x-ui.link>
                @elseif ($editing)
                    <x-ui.link x-on:click="openNew('{{ $type->value }}')" icon="add" class="w-full py-[13px] text-[15px]" data-test="add-{{ $type->value }}">{{ __('New item') }}</x-ui.link>
                @endif
                {{-- With a single line the subtotal would only repeat it. --}}
                @if ($lines->count() > 1)
                    <div class="flex justify-between py-[13px]" data-test="subtotal">
                        <span class="text-sm text-muted">{{ __('Subtotal') }}</span>
                        <x-ui.amount size="sm" :value="$summary->totalFor($type)" class="text-[15px] font-semibold" />
                    </div>
                @endif
            </div>
        </section>
    @endforeach

    @php
        $monthEnd = $this->monthEnd;
        $settings = $this->user()->settings();
        $currentTarget = $settings->surplus_account_id !== null ? 'account:'.$settings->surplus_account_id : ($settings->surplus_pocket_id !== null ? 'pocket:'.$settings->surplus_pocket_id : '');
        $targetName = $this->surplusTargets[$currentTarget] ?? __('Stays on the account');
        $reservePocket = $this->reservePocket;
        $fixedMode = $settings->reserve_fixed !== null;
    @endphp
    {{--
        The leftover rules, inline as on the onboarding: the sentence sums them up, every choice
        below saves at once. Two levels only: the cards hold their fields as rows, no boxes inside.
    --}}
    <section class="mt-[22px] px-4" data-test="month-end">
        <x-ui.section-label group :label="__('Reserve and leftover')" class="!pb-0.5" />
        <p class="px-1.5 text-xs text-muted">{{ __('What you choose here is saved right away.') }}</p>
        <div class="px-1.5 pb-2 pt-3">
            <p class="num text-[15px] font-medium leading-snug text-pretty" data-test="leftover-rule">{{ $this->leftoverRule() }}</p>
            <p class="min-h-5 text-[13px] font-medium text-accent-strong" role="status" aria-live="polite">
                @if ($ruleSavedTick > 0)
                    <span wire:key="rule-saved-{{ $ruleSavedTick }}" x-data x-init="setTimeout(() => $el.remove(), 3000)" class="inline-flex items-center gap-1" data-test="rule-saved"><x-ui.icon name="check" :size="16" />{{ __('Leftover rule saved.') }}</span>
                @endif
            </p>
        </div>

        <div class="rounded-card bg-surface px-[18px]" data-test="reserve-card">
            <div class="flex items-center gap-3 py-4">
                <x-ui.icon-tile icon="shield" />
                <div class="min-w-0 flex-1">
                    <div class="text-[15px] font-medium leading-snug">{{ __('Reserve pocket') }}</div>
                    <div class="num mt-0.5 text-[13px] text-muted">{{ $reservePocket ? __('Balance: :amount', ['amount' => money($reservePocket->balance)]) : __('A pocket for the unexpected') }}</div>
                </div>
            </div>
            @if ($reservePocket)
                <p class="-mt-1 pb-3 text-[13px] leading-snug text-pretty text-muted">{{ __('Money you put aside for the unexpected. If a month ends in the red, at closing you choose whether to take the gap from here.') }}</p>
                <div class="divide-y divide-line border-t border-line">
                    @foreach ([
                        ['label' => __('Put aside every month'), 'value' => $monthEnd->reserveMonthly, 'hint' => __('Planned like a fixed cost and moved into the pocket at closing. 0 is fine too.')],
                        ['label' => __('Target'), 'value' => $reservePocket->target_amount, 'hint' => __('Until the pocket reaches it, the month-end leftover also tops it up. Empty means no limit.')],
                    ] as $row)
                        <button type="button" x-on:click="openReserve()" class="focus-ring flex min-h-14 w-full items-center justify-between gap-3 py-3 text-left" data-test="reserve-row">
                            <span class="min-w-0">
                                <span class="block text-[15px]">{{ $row['label'] }}</span>
                                <span class="mt-0.5 block text-xs leading-snug text-pretty text-muted">{{ $row['hint'] }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-1">
                                @if ($row['value'] === null)<span class="text-[15px] text-muted">{{ __('Not set') }}</span>@else<x-ui.amount size="sm" :value="$row['value']" class="text-[17px] font-semibold" />@endif
                                <x-ui.icon name="edit" :size="16" class="text-faint" />
                            </span>
                        </button>
                    @endforeach
                </div>
            @else
                <p class="-mt-1 text-[13px] leading-snug text-pretty text-muted">{{ __('No reserve pocket: the whole month-end leftover goes to the target below. You can add one later under Pockets.') }}</p>
                <x-ui.button variant="secondary" size="md" icon="add" x-on:click="openReserve()" class="mb-4 mt-3 w-full" data-test="create-reserve">{{ __('Create a reserve pocket') }}</x-ui.button>
            @endif
        </div>

        <x-ui.section-label :label="__('If money is left at month end')" class="mt-5" />
        <div class="rounded-card bg-surface px-[18px] py-4">
            @if ($monthEnd->hasReserve)
                <p class="text-[13px] leading-snug text-pretty text-muted">{{ __('On top of the monthly amount, this much of the leftover also goes to the reserve until it reaches the target:') }}</p>
                <div class="mt-3 grid grid-cols-2 gap-[3px] rounded-control bg-bg p-[3px]">
                    @foreach (['pct' => __('A share'), 'fixed' => __('A fixed amount')] as $mode => $modeLabel)
                        @php $isSelected = ($mode === 'fixed') === $fixedMode; @endphp
                        <button type="button" wire:click="setReserveMode('{{ $mode }}')" aria-pressed="{{ $isSelected ? 'true' : 'false' }}" @class(['focus-ring h-11 rounded-[10px] text-[13px] font-medium transition-colors', 'bg-surface-3 text-ink' => $isSelected, 'text-muted' => ! $isSelected]) data-test="reserve-mode-{{ $mode }}">{{ $modeLabel }}</button>
                    @endforeach
                </div>
                @if ($fixedMode)
                    <button type="button" x-on:click="openReserve('fixed')" class="focus-ring mt-3 flex min-h-14 w-full items-center justify-between gap-3 border-y border-line py-3 text-left" data-test="reserve-fixed">
                        <span class="text-[15px]">{{ __('Amount from the leftover') }}</span>
                        <span class="flex shrink-0 items-center gap-1">
                            <x-ui.amount size="sm" :value="$settings->reserve_fixed" class="text-[17px] font-semibold" />
                            <x-ui.icon name="edit" :size="16" class="text-faint" />
                        </span>
                    </button>
                @else
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ([0, 25, 50, 100] as $pct)
                            <x-ui.choice :selected="$settings->reserve_pct === $pct" wire:click="setReservePct({{ $pct }})" class="num h-11 rounded-control text-[15px]" data-test="reserve-pct-{{ $pct }}">{{ $pct }}%</x-ui.choice>
                        @endforeach
                    </div>
                @endif
            @endif
            <div @class(['text-[13px] font-semibold text-muted', 'mt-5' => $monthEnd->hasReserve])>{{ $monthEnd->hasReserve ? __('The rest goes to:') : __('All of it goes to:') }}</div>
            <div class="mt-2 grid gap-2" data-test="surplus-target">
                @foreach ($this->surplusTargets as $value => $name)
                    <x-ui.choice :selected="$value === $currentTarget" wire:click="setSurplusTarget('{{ $value }}')" class="rounded-control px-4 py-2.5 text-left" wire:key="target-{{ $value ?: 'none' }}">
                        <span class="block truncate text-[15px] font-semibold">{{ $name }}</span>
                        <span class="mt-0.5 block truncate text-[13px] font-normal text-muted">{{ match (true) {
                            str_starts_with($value, 'account:') => __('A manual transfer; we remind you'),
                            str_starts_with($value, 'pocket:') => __('Added here in the app at closing'),
                            default => __('Nothing is moved'),
                        } }}</span>
                    </x-ui.choice>
                @endforeach
            </div>
        </div>

        {{-- Information, not an action: a neutral callout. --}}
        <x-ui.callout :title="__('A month with your numbers')" class="mt-3" data-test="leftover-preview">
            <div class="num mt-2">
                <div class="flex justify-between gap-3 py-1"><span>{{ __('Income') }}</span><span class="text-ink">{{ money($monthEnd->income) }}</span></div>
                <div class="flex justify-between gap-3 py-1"><span>{{ __('Planned costs and budgets') }}</span><span class="text-ink">{{ $monthEnd->planned > 0 ? '−' : '' }}{{ money($monthEnd->planned) }}</span></div>
                @if ($monthEnd->hasReserve)
                    <div class="flex justify-between gap-3 py-1"><span>{{ __('Put aside in the reserve') }}</span><span class="text-ink">{{ $monthEnd->reserveMonthly > 0 ? '−' : '' }}{{ money($monthEnd->reserveMonthly) }}</span></div>
                @endif
                <div @class(['mt-1 flex justify-between gap-3 border-t border-line pt-2 font-semibold', 'text-danger' => $monthEnd->leftover < 0, 'text-ink' => $monthEnd->leftover >= 0])><span>{{ __('Expected leftover') }}</span><span>{{ money($monthEnd->leftover) }}</span></div>
            </div>
            @if ($monthEnd->leftover > 0)
                <div class="mt-3 font-semibold text-ink">{{ __('Splitting the leftover') }}</div>
                <div class="num mt-1">
                    @if ($monthEnd->hasReserve)
                        <div class="flex justify-between gap-3 py-1"><span>{{ __('Reserve: :fixed fixed + :share from the leftover', ['fixed' => money($monthEnd->reserveMonthly), 'share' => money($monthEnd->allocation->toReserve)]) }}</span><span class="shrink-0 font-semibold text-ink">{{ money($monthEnd->toReserveTotal()) }}</span></div>
                    @endif
                    <div class="flex justify-between gap-3 py-1"><span>{{ $targetName }}</span><span class="shrink-0 font-semibold text-ink">{{ money($monthEnd->allocation->toSurplus) }}</span></div>
                </div>
            @elseif ($monthEnd->leftover === 0)
                <p class="mt-2 text-ink-2">{{ __('Your plan uses up the whole income, so nothing is left to share out.') }}</p>
            @else
                <p class="mt-2 text-ink-2">{{ __('Your plan is :gap over the income a month.', ['gap' => money(-$monthEnd->leftover)]) }} {{ __('It is worth lowering a budget.') }}</p>
            @endif
            <p class="mt-2 text-xs">{{ __('Planned figures. At closing the real leftover is shared out the same way, and you can adjust it there.') }}</p>
        </x-ui.callout>
    </section>

    <div class="px-4 pt-5">
        @if ($editing)
            <x-ui.button variant="tonal" size="md" icon="check" class="w-full" wire:click="$set('editing', false)" data-test="done-editing">{{ __('Done') }}</x-ui.button>
        @else
            <x-ui.button variant="secondary" size="md" icon="add" class="w-full" wire:click="$set('editing', true)" data-test="start-editing">{{ __('Add or reorder items') }}</x-ui.button>
        @endif
    </div>

    {{-- Line editor: the amount first (the usual change), the rarely needed settings behind "More". --}}
    <x-ui.sheet show="sheet === 'line'" close="sheet = null" title="lineTitle()" :full="false" data-test="line-sheet">
        <x-ui.form-group>
            <template x-if="line.type !== 'variable'">
                <x-ui.amount-row name="amount" :label="__('Monthly amount')" fallback="0" error="form.amount" data-test="field-amount" />
            </template>
            <template x-if="line.type === 'variable'">
                <x-ui.amount-row name="amount" :label="__('Monthly budget')" fallback="0" error="form.amount" data-test="field-amount" />
            </template>
        </x-ui.form-group>
        @if ($scopeNote)
            <p class="px-1.5 pt-2 text-xs leading-snug text-muted" data-test="scope-note">{{ $scopeNote }}</p>
        @endif

        <x-ui.form-group :title="__('Basics')">
            <x-ui.text-row :label="__('Name')" x-model="line.name" maxlength="80" :placeholder="__('e.g. Rent')" error="form.name" data-test="line-name" />
            <template x-if="line.type !== 'variable'">
                <x-ui.day-field :label="__('Due day')" value="fields.dueDay" pick="fields.dueDay = day === null ? '' : String(day)" error="form.dueDay" data-test="field-due-day" />
            </template>
        </x-ui.form-group>

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

        <div class="mt-5">
            <button type="button" x-on:click="more = ! more" :aria-expanded="more ? 'true' : 'false'" class="focus-ring flex min-h-11 w-full items-center justify-between gap-3 rounded-xl px-1.5 text-left text-[15px] font-medium text-ink-2" data-test="line-more">
                <span>{{ __('More') }}<span class="block text-xs font-normal text-muted">{{ __('Kind, typical and expensive month, icon, active period') }}</span></span>
                <x-ui.icon name="expand_more" :size="22" class="text-faint transition duration-200 ease-out" ::class="more && 'rotate-180'" />
            </button>
        </div>

        <div x-show="more" x-collapse.duration.220ms x-cloak data-test="line-more-panel">
            <x-ui.form-group :title="__('Kind')">
                <div class="p-3">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($types as $type)
                            <button type="button" x-on:click="line.type = @js($type->value)" :aria-pressed="line.type === @js($type->value) ? 'true' : 'false'" class="focus-ring min-h-11 rounded-xl border px-3.5 text-[13px] font-medium" :class="line.type === @js($type->value) ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-3 text-ink-2'" data-test="type-{{ $type->value }}">{{ $this->sectionTitle($type) }}</button>
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

            <template x-if="line.type === 'variable'">
                <div>
                    <x-ui.form-group :title="__('Typical and expensive month')" :hint="__('Optional. With both filled in, the plan can count with either.')">
                        <x-ui.amount-row name="amountAvg" :label="__('Typical month')" error="form.amountAvg" />
                        <x-ui.amount-row name="amountMax" :label="__('Expensive month')" error="form.amountMax" />
                        <div class="p-3">
                            <div class="mb-2 px-0.5 text-[13px] text-muted">{{ __('The plan counts with') }}</div>
                            <x-ui.segmented model="line.calcMode" :options="['fixed' => __('Budget amount'), 'avg' => __('Typical'), 'max' => __('Expensive')]" />
                        </div>
                    </x-ui.form-group>
                    <x-ui.form-group>
                        <x-ui.switch-row model="line.isQuickEntry" :label="__('Show on the quick entry screen')" />
                    </x-ui.form-group>
                </div>
            </template>

            <x-ui.form-group :title="__('Icon')">
                {{-- 48 px tiles, seven to a row (six on a 320 px phone, so each stays a fair target). --}}
                <div class="grid grid-cols-6 gap-1.5 p-2.5 min-[360px]:grid-cols-7" role="group" aria-label="{{ __('Icon') }}">
                    @foreach (Icons::CATEGORY as $icon)
                        <button type="button" x-on:click="line.icon = @js($icon)" :aria-pressed="line.icon === @js($icon) ? 'true' : 'false'" class="focus-ring flex h-12 items-center justify-center rounded-small" :class="line.icon === @js($icon) ? 'bg-accent/14 text-accent ring-1 ring-accent' : 'bg-surface-3 text-ink-2'" aria-label="{{ Icons::label($icon) }}" data-test="icon-{{ $icon }}">
                            <x-ui.icon :name="$icon" :size="22" />
                        </button>
                    @endforeach
                </div>
            </x-ui.form-group>

            <x-ui.form-group :title="__('Active period')" :hint="__('Optional.')">
                <label class="flex min-h-[52px] items-center justify-between gap-3 px-4 focus-within:bg-accent/10"><span class="text-[15px]">{{ __('Active from') }}</span><input type="date" x-model="line.activeFrom" class="bg-transparent text-right text-[15px] text-ink-2 outline-none"></label>
                <label class="flex min-h-[52px] items-center justify-between gap-3 px-4 focus-within:bg-accent/10"><span class="text-[15px]">{{ __('Active until') }}</span><input type="date" x-model="line.activeTo" class="bg-transparent text-right text-[15px] text-ink-2 outline-none"></label>
            </x-ui.form-group>
            <p class="px-1.5 pt-2 text-xs text-danger" x-show="errors['form.activeTo']" x-text="errors['form.activeTo']"></p>
        </div>

        {{-- Deleting is rare and destructive: at the very end of the sheet, not in the header. --}}
        <x-slot:danger>
            <x-ui.button variant="danger" size="md" icon="delete" x-show="line.lineId" x-on:click="removeLine()" class="w-full" data-test="delete-line">{{ __('Delete item') }}</x-ui.button>
        </x-slot:danger>

        <x-slot:footer>
            <x-ui.button x-on:click="saveLine()" ::disabled="saving" class="w-full" data-test="save-line">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$this->currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>

    {{-- Income editor --}}
    <x-ui.numpad-sheet show="sheet === 'income'" close="sheet = null" :label="__('Monthly net income')" done="saveIncome()" display="display('income')" :decimal="$this->currency->decimals() > 0" error="incomeError" :intro="\Illuminate\Support\Js::from(__('Monthly, after tax.'))->toHtml()" data-test="income-sheet" />

    {{-- Reserve amounts: monthly saving, target, fixed share of the leftover. --}}
    <x-ui.sheet show="sheet === 'reserve'" close="sheet = null" :label="__('Reserve pocket')" data-test="reserve-sheet">
        <x-ui.form-group>
            <x-ui.amount-row name="monthly" :label="__('Put aside every month')" fallback="0" error="monthly" data-test="reserve-monthly" />
            <x-ui.amount-row name="target" :label="__('Target')" :hint="__('Empty means no limit')" fallback="" error="target" data-test="reserve-target" />
            <template x-if="reserveFixedMode">
                <x-ui.amount-row name="fixed" :label="__('Amount from the leftover')" fallback="0" error="fixed" data-test="reserve-fixed-amount" />
            </template>
        </x-ui.form-group>
        <x-slot:footer>
            <x-ui.button x-on:click="saveReserve()" ::disabled="saving" class="w-full" data-test="save-reserve">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$this->currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>
</div>

@script
<script>
    Alpine.data('planPage', ({ decimals, locale, openLineId }) => ({
        sheet: null,
        line: {},
        more: false,
        errors: {},
        saving: false,
        incomeError: null,
        ...window.amountFields({ decimals, locale }),

        init() {
            if (openLineId) {
                this.openLine(openLineId)
                history.replaceState(null, '', location.pathname)
            }
        },

        /** One place up or down; focus stays on the arrow so it can be pressed again. */
        async moveLine(categoryId, direction) {
            await $wire.move(categoryId, direction)
            this.$nextTick(() => {
                const button = document.querySelector(`[data-move="${categoryId}:${direction}"]`)
                ;(button && ! button.disabled ? button : document.querySelector(`[data-move="${categoryId}:${-direction}"]`))?.focus()
            })
        },

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
            this.more = false
            this.sheet = 'line'
        },
        async saveLine(typeChangeConfirmed = false) {
            this.saving = true
            let result
            try {
                result = await $wire.saveLine({ ...this.line, amounts: { ...this.fields }, typeChangeConfirmed })
                this.errors = result.errors ?? {}
                // An error in a setting behind "More" opens it, so the message is seen.
                if (['form.type', 'form.amountAvg', 'form.amountMax', 'form.activeFrom', 'form.activeTo', 'form.icon'].some((key) => this.errors[key])) this.more = true
                // Focus goes to the first field with an error, which tells what is wrong (aria-describedby).
                if (! result.ok) this.$nextTick(() => document.querySelector('[data-test=line-sheet] [aria-invalid=true]')?.focus())
                if (result.ok) this.sheet = null
            } finally {
                this.saving = false
            }
            // The server holds a type change back when it recounts this period's spending.
            if (result.confirm && await window.appConfirm(result.confirm)) await this.saveLine(true)
        },
        async removeLine() {
            if (! await window.appConfirm({ title: @js(__('Remove this line from the plan?')), body: this.line.deleteNote, confirm: @js(__('Remove')), danger: true })) return
            await $wire.deleteLine(this.line.lineId)
            this.sheet = null
        },

        openIncome(value) {
            this.fields = {}
            this.editAmount('income', value)
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
