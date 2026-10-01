<?php

use App\Actions\Budget\DeletePlanLine;
use App\Actions\Budget\ReorderCategories;
use App\Actions\Budget\SavePlanLine;
use App\Enums\CalcMode;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Livewire\Forms\PlanLineForm;
use App\Models\BudgetLine;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Services\BudgetCalculator;
use App\Services\Data\PlanSummary;
use App\Services\PeriodService;
use App\Services\PlanService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan')] class extends Component {
    public PlanLineForm $form;

    public string $income = '';

    /** @var array<int, string> */
    public array $amounts = [];

    public function mount(): void
    {
        $this->income = Money::toInput($this->user()->settings()->income, $this->currency);
        $this->fillAmounts();
    }

    public function updatedIncome(): void
    {
        $income = Money::parse($this->income, $this->currency);

        if ($income === null) {
            $this->addError('income', __('Enter a valid amount.'));

            return;
        }

        $this->resetErrorBag('income');
        $this->user()->settings()->update(['income' => $income]);
        $this->currentPeriod()->update(['income_planned' => $income]);
        $this->refreshPlan();
    }

    public function updatedAmounts(string $value, string $lineId): void
    {
        $amount = Money::parse($value, $this->currency);
        $line = $this->lines->firstWhere('id', (int) $lineId);

        if ($amount === null || ! $line instanceof BudgetLine) {
            $this->addError('amounts.'.$lineId, __('Enter a valid amount.'));

            return;
        }

        $this->resetErrorBag('amounts.'.$lineId);
        $column = match ($line->calc_mode) {
            CalcMode::Avg => 'amount_avg',
            CalcMode::Max => 'amount_max',
            CalcMode::Fixed => 'amount',
        };
        $line->update([$column => $amount]);
        $this->refreshPlan();
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
    }

    public function create(string $type): void
    {
        $this->resetValidation();
        $this->form->startNew(LineType::from($type));
        $this->modal('line-editor')->show();
    }

    public function edit(int $lineId): void
    {
        $line = $this->lines->firstWhere('id', $lineId);
        abort_unless($line instanceof BudgetLine, 404);

        $this->resetValidation();
        $this->form->fillFrom($line, $this->currency);
        $this->modal('line-editor')->show();
    }

    public function save(SavePlanLine $savePlanLine): void
    {
        $line = $this->form->lineId !== null ? $this->lines->firstWhere('id', $this->form->lineId) : null;

        $savePlanLine->handle($this->user(), $this->form->payload($this->currency, $this->user()->id), $line);

        $this->modal('line-editor')->close();
        $this->form->reset();
        $this->refreshPlan();

        Flux::toast(variant: 'success', text: __('Plan updated.'));
    }

    public function delete(DeletePlanLine $deletePlanLine): void
    {
        $line = $this->lines->firstWhere('id', $this->form->lineId);
        abort_unless($line instanceof BudgetLine, 404);

        $deletePlanLine->handle($line);

        $this->modal('line-editor')->close();
        $this->form->reset();
        $this->refreshPlan();
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
        return app(PlanService::class)->budgetLines($this->user());
    }

    /**
     * @return array<string, Collection<int, BudgetLine>>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach (LineType::cases() as $type) {
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
        $this->fillAmounts();
    }

    private function fillAmounts(): void
    {
        $this->amounts = [];

        foreach ($this->lines as $line) {
            $this->amounts[$line->id] = Money::toInput($this->plannedAmount($line), $this->currency);
        }
    }

    private function currentPeriod(): \App\Models\Period
    {
        return app(PeriodService::class)->current($this->user());
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div class="flex flex-col gap-6">
    <flux:card class="flex items-end gap-3">
        <flux:input wire:model.blur="income" :label="__('Monthly net income')" inputmode="decimal" class="flex-1" data-test="income" />
        <flux:badge size="sm" class="mb-2">{{ $this->currency->value }}</flux:badge>
    </flux:card>

    @foreach (LineType::cases() as $type)
        @php($lines = $this->groups[$type->value])
        <section wire:key="group-{{ $type->value }}" class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <flux:heading>{{ $type->label() }}</flux:heading>
                <flux:text class="text-sm"><x-money :amount="$this->summary->totalFor($type)" /></flux:text>
            </div>

            @if ($lines->isEmpty())
                <flux:text class="text-sm">{{ __('Nothing here yet.') }}</flux:text>
            @else
                <ul wire:sort="reorder" class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($lines as $line)
                        <li wire:key="line-{{ $line->id }}" wire:sort:item="{{ $line->category_id }}" class="flex items-center gap-3 px-3 py-2">
                            <span wire:sort:handle class="cursor-grab touch-none text-zinc-400" aria-label="{{ __('Drag to reorder') }}">
                                <flux:icon.bars-2 class="size-4" />
                            </span>
                            <x-category-icon :category="$line->category" class="size-5 shrink-0" />
                            <button type="button" wire:click="edit({{ $line->id }})" class="min-w-0 flex-1 text-start">
                                <span class="block truncate text-sm font-medium">{{ $line->category?->name }}</span>
                                <span class="flex flex-wrap gap-x-2 text-xs text-zinc-500">
                                    @if ($line->due_day)
                                        <span>{{ __('Due on day :day', ['day' => $line->due_day]) }}</span>
                                    @endif
                                    @if ($line->active_to)
                                        <span>{{ __('Until :date', ['date' => $line->active_to->isoFormat('YYYY. MM. DD.')]) }}</span>
                                    @endif
                                    @if ($line->orig_currency)
                                        <span>{{ money((int) $line->orig_amount, \App\Enums\Currency::from($line->orig_currency)) }}</span>
                                    @endif
                                </span>
                            </button>

                            @if ($type === LineType::Variable && ($line->amount_avg !== null || $line->amount_max !== null))
                                <div class="flex overflow-hidden rounded-lg border border-zinc-200 text-xs dark:border-zinc-600">
                                    @foreach ([CalcMode::Avg, CalcMode::Max] as $mode)
                                        <button type="button" wire:click="setCalcMode({{ $line->id }}, '{{ $mode->value }}')"
                                                @class([
                                                    'px-2 py-1',
                                                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $line->calc_mode === $mode,
                                                ])>{{ $mode === CalcMode::Avg ? __('avg') : __('max') }}</button>
                                    @endforeach
                                </div>
                            @endif

                            <flux:input wire:model.blur="amounts.{{ $line->id }}" inputmode="decimal" size="sm" class="!w-28 text-end" aria-label="{{ __('Amount') }}" />
                        </li>
                    @endforeach
                </ul>
            @endif

            <flux:button size="sm" variant="ghost" icon="plus" wire:click="create('{{ $type->value }}')" class="self-start" data-test="add-{{ $type->value }}">
                {{ __('Add') }}
            </flux:button>
        </section>
    @endforeach

    <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-10 rounded-2xl bg-zinc-900 p-4 text-white shadow-lg dark:bg-white dark:text-zinc-900" data-test="plan-summary">
        <div class="flex justify-between text-sm opacity-80">
            <span>{{ __('Total expenses') }}</span>
            <x-money :amount="$this->summary->totalExpenses" />
        </div>
        <div class="flex items-baseline justify-between">
            <span class="font-medium">{{ __('Planned leftover') }}</span>
            <x-money :amount="$this->summary->leftover" @class(['text-2xl font-semibold', 'text-red-400 dark:text-red-600' => $this->summary->leftover < 0]) />
        </div>
    </div>

    <flux:modal name="line-editor" class="max-h-[90dvh]" variant="flyout" position="bottom">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $form->lineId ? __('Edit line') : __('New line') }}</flux:heading>

            <flux:input wire:model="form.name" :label="__('Name')" required />

            <flux:select wire:model.live="form.type" :label="__('Type')">
                @foreach (LineType::cases() as $type)
                    <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="form.amount" :label="$form->type === 'variable' ? __('Budget') : __('Amount')" inputmode="decimal" required />

            @if ($form->type === 'variable')
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="form.amountAvg" :label="__('Average')" inputmode="decimal" />
                    <flux:input wire:model="form.amountMax" :label="__('Maximum')" inputmode="decimal" />
                </div>
                <flux:radio.group wire:model="form.calcMode" :label="__('Plan with')" variant="segmented">
                    @foreach (CalcMode::cases() as $mode)
                        <flux:radio :value="$mode->value" :label="$mode === CalcMode::Fixed ? __('Budget') : $mode->label()" />
                    @endforeach
                </flux:radio.group>
                <flux:switch wire:model="form.isQuickEntry" :label="__('Show on the quick entry screen')" />
            @else
                <flux:input type="number" wire:model="form.dueDay" :label="__('Due day')" min="1" max="31" inputmode="numeric" />
            @endif

            @if (in_array($form->type, ['transfer', 'sinking'], true))
                <flux:select wire:model="form.pocketId" :label="__('Pocket')" :placeholder="__('None')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->pockets as $pocket)
                        <flux:select.option :value="$pocket->id">{{ $pocket->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($form->type === 'loan')
                <flux:select wire:model="form.loanId" :label="__('Loan')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->loans as $loan)
                        <flux:select.option :value="$loan->id">{{ $loan->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <details class="text-sm">
                <summary class="cursor-pointer text-zinc-500">{{ __('More options') }}</summary>
                <div class="mt-3 grid gap-3">
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="date" wire:model="form.activeFrom" :label="__('Active from')" />
                        <flux:input type="date" wire:model="form.activeTo" :label="__('Active until')" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input wire:model="form.origAmount" :label="__('Original amount')" inputmode="decimal" />
                        <flux:select wire:model="form.origCurrency" :label="__('Currency')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (Currency::cases() as $currency)
                                <flux:select.option :value="$currency->value">{{ $currency->value }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:input wire:model="form.icon" :label="__('Icon')" :description="__('A Heroicons name, e.g. shopping-cart')" />
                    <flux:input wire:model="form.note" :label="__('Note')" />
                </div>
            </details>

            <div class="flex gap-2">
                @if ($form->lineId)
                    <flux:button variant="danger" wire:click="delete" wire:confirm="{{ __('Remove this line from the plan?') }}" icon="trash" aria-label="{{ __('Delete') }}" />
                @endif
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-line">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
