<?php

use App\Actions\Budget\DeletePocket;
use App\Actions\Budget\RestorePocket;
use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\PayFromPocket;
use App\Enums\LineType;
use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\SaveLoan;
use App\Actions\Budget\UndoPocketMovement;
use App\Enums\Currency;
use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Services\Data\LoanState;
use App\Services\LoanCalculator;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pockets and loans')] class extends Component {
    /**
     * @return array<string, mixed>
     */
    public function pocketData(?int $pocketId = null): array
    {
        $pocket = $pocketId === null ? null : $this->user()->pockets()->findOrFail($pocketId);

        return [
            'id' => $pocket?->id,
            'name' => $pocket->name ?? '',
            'balance' => $pocket->balance ?? 0,
            'target' => $pocket?->target_amount,
            'isReserve' => $pocket->is_reserve ?? false,
            'isShared' => $pocket->is_shared ?? false,
            'loanId' => $pocket?->loan_id,
            'movements' => $pocket === null ? [] : $pocket->movements()->latest('occurred_on')->latest('id')->limit(5)->get()
                ->map(fn ($movement): array => [
                    'date' => \App\Support\Dates::short($movement->occurred_on),
                    // A direction sign on every movement, with a real minus (U+2212) for money going out.
                    'amount' => ($movement->amount < 0 ? "\u{2212}" : '+').money(abs($movement->amount)),
                    'negative' => $movement->amount < 0,
                    'label' => $movement->note ?? $movement->type->label(),
                ])->all(),
            'amounts' => [
                'move' => '',
                'target' => $this->input($pocket?->target_amount),
                'step' => $this->input($pocket?->prepay_step),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function savePocket(array $data): array
    {
        $user = $this->user();
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];

        $validator = Validator::make([
            'name' => $data['name'] ?? null,
            'target' => $amounts['target'] ?? null,
            'step' => $amounts['step'] ?? null,
            'loanId' => $data['loanId'] ?? null,
        ], [
            'name' => ['required', 'string', 'max:80'],
            'target' => ['nullable', $this->moneyRule()],
            'step' => ['nullable', $this->moneyRule()],
            'loanId' => ['nullable', 'integer', Rule::exists('loans', 'id')->where('user_id', $user->id)],
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => array_map(fn (array $m): string => $m[0], $validator->errors()->toArray())];
        }

        $id = is_numeric($data['id'] ?? null) ? (int) $data['id'] : null;
        $isReserve = (bool) ($data['isReserve'] ?? false);

        $attributes = [
            'name' => trim((string) $data['name']),
            'target_amount' => $this->parse($amounts['target'] ?? ''),
            'prepay_step' => $this->parse($amounts['step'] ?? ''),
            'is_reserve' => $isReserve,
            'is_shared' => (bool) ($data['isShared'] ?? false),
            'loan_id' => is_numeric($data['loanId'] ?? null) ? (int) $data['loanId'] : null,
        ];

        if ($isReserve) {
            $user->pockets()->where('id', '!=', $id ?? 0)->update(['is_reserve' => false]);
        }

        if ($id !== null) {
            $user->pockets()->findOrFail($id)->update($attributes);
        } else {
            $user->pockets()->create([...$attributes, 'sort' => $user->pockets()->count() + 1]);
        }

        unset($this->pockets);
        $this->dispatch('app-toast', title: __('Pocket saved.'));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Deposit (direction 1) or withdraw (-1). With $toBudget the money moves against this
     * period: a withdrawal tops up its budget, a deposit is taken off its leftover.
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function movePocketMoney(int $pocketId, int $direction, string $amount, ?string $note, MovePocketMoney $movePocketMoney, bool $toBudget = true): array
    {
        $value = $this->parse($amount);

        if ($value === null || $value <= 0) {
            return ['ok' => false, 'errors' => ['move' => __('Enter a valid amount.')]];
        }

        $pocket = $this->user()->pockets()->findOrFail($pocketId);

        try {
            $movement = $movePocketMoney->handle($this->user(), $pocket, ($direction < 0 ? -1 : 1) * $value, $note, $toBudget);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => ['move' => (string) collect($exception->errors())->flatten()->first()]];
        }

        unset($this->pockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast',
            title: $direction < 0 ? __('Withdrawn: :amount', ['amount' => money($value)]) : __('Deposited: :amount', ['amount' => money($value)]),
            subtitle: match (true) {
                ! $toBudget => null,
                $direction < 0 => __('This month’s leftover went up by :amount.', ['amount' => money($value)]),
                default => __('This month’s leftover went down by :amount.', ['amount' => money($value)]),
            },
            undo: 'undo-pocket-movement',
            params: ['id' => $movement->id],
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Pay a spending from the pocket: recorded in the month, does not use up the budget.
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function payFromPocket(int $pocketId, string $amount, ?int $categoryId, ?string $note, PayFromPocket $payFromPocket): array
    {
        $value = $this->parse($amount);

        if ($value === null || $value <= 0) {
            return ['ok' => false, 'errors' => ['move' => __('Enter a valid amount.')]];
        }

        if ($categoryId === null) {
            return ['ok' => false, 'errors' => ['category' => __('Choose a category.')]];
        }

        try {
            $payFromPocket->handle($this->user(), $this->user()->pockets()->findOrFail($pocketId), $categoryId, $value, filled($note) ? $note : null);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => ['move' => (string) collect($exception->errors())->flatten()->first()]];
        }

        unset($this->pockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Paid from the pocket: :amount', ['amount' => money($value)]), subtitle: __('It does not use up this period’s budget.'));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * The toast's Undo after a deposit or withdrawal: the movement goes, the balance and the month's leftover come back.
     */
    #[On('undo-pocket-movement')]
    public function undoPocketMovement(int $id, UndoPocketMovement $undoPocketMovement): void
    {
        try {
            $movement = $undoPocketMovement->handle($this->user(), $id);
        } catch (ValidationException $exception) {
            $this->dispatch('app-toast', title: (string) collect($exception->errors())->flatten()->first(), icon: 'error');

            return;
        }

        unset($this->pockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Money movement undone.'), subtitle: __(':pocket balance: :amount', ['pocket' => $movement->pocket->name, 'amount' => money($movement->pocket->balance)]), icon: 'undo');
    }

    public function deletePocket(int $pocketId, DeletePocket $deletePocket): void
    {
        $pocket = $this->user()->pockets()->findOrFail($pocketId);
        $deletePocket->handle($pocket);
        unset($this->pockets, $this->archivedPockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __(':pocket archived', ['pocket' => $pocket->name]), icon: 'delete', undo: 'restore-pocket', params: ['id' => $pocket->id]);
    }

    #[On('restore-pocket')]
    public function restorePocket(int $id, RestorePocket $restorePocket): void
    {
        $pocket = $this->user()->pockets()->onlyTrashed()->find($id);

        if ($pocket === null) {
            return;
        }

        $restorePocket->handle($pocket);
        unset($this->pockets, $this->archivedPockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __(':pocket restored', ['pocket' => $pocket->name]), subtitle: __('Its monthly saving is not back in the plan; add it again if you need it.'), icon: 'undo');
    }

    /**
     * @return array<string, mixed>
     */
    public function loanData(?int $loanId = null): array
    {
        $loan = $loanId === null ? null : $this->user()->loans()->findOrFail($loanId);

        return [
            'id' => $loan?->id,
            'name' => $loan->name ?? '',
            'lender' => $loan->lender ?? '',
            'prepayMode' => ($loan->prepay_mode ?? PrepayMode::ReduceInstallment)->value,
            'amounts' => [
                'principal' => $this->input($loan?->principal_balance),
                'installment' => $this->input($loan?->installment),
                'insurance' => $this->input($loan?->insurance),
                'thm' => $loan?->thm === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format($loan->thm, 3, '.', ''), '0'), '.')),
                'months' => $loan?->remaining_months === null ? '' : (string) $loan->remaining_months,
                'dueDay' => (string) ($loan?->budgetLines()->value('due_day') ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function saveLoan(array $data, SaveLoan $saveLoan): array
    {
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $thm = str_replace(',', '.', is_scalar($amounts['thm'] ?? null) ? (string) $amounts['thm'] : '');

        $validator = Validator::make([
            'name' => $data['name'] ?? null,
            'lender' => $data['lender'] ?? null,
            'principal' => $amounts['principal'] ?? null,
            'installment' => $amounts['installment'] ?? null,
            'insurance' => $amounts['insurance'] ?? null,
            'thm' => $thm === '' ? null : $thm,
            'months' => ($amounts['months'] ?? '') === '' ? null : $amounts['months'],
            'dueDay' => ($amounts['dueDay'] ?? '') === '' ? null : $amounts['dueDay'],
            'prepayMode' => $data['prepayMode'] ?? null,
        ], [
            'name' => ['required', 'string', 'max:80'],
            'lender' => ['nullable', 'string', 'max:80'],
            'principal' => ['required', $this->moneyRule()],
            'installment' => ['required', $this->moneyRule()],
            'insurance' => ['nullable', $this->moneyRule()],
            'thm' => ['nullable', 'numeric', 'between:0,100'],
            'months' => ['nullable', 'integer', 'between:1,600'],
            'dueDay' => ['nullable', 'integer', 'between:1,31'],
            'prepayMode' => ['required', Rule::enum(PrepayMode::class)],
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => array_map(fn (array $m): string => $m[0], $validator->errors()->toArray())];
        }

        $id = is_numeric($data['id'] ?? null) ? (int) $data['id'] : null;

        $saveLoan->handle($this->user(), [
            'name' => trim((string) $data['name']),
            'lender' => filled($data['lender'] ?? null) ? trim((string) $data['lender']) : null,
            'principal_balance' => (int) $this->parse((string) $amounts['principal']),
            'installment' => (int) $this->parse((string) $amounts['installment']),
            'insurance' => (int) $this->parse((string) ($amounts['insurance'] ?? '')),
            'thm' => $thm === '' ? null : (float) $thm,
            'remaining_months' => ($amounts['months'] ?? '') === '' ? null : (int) $amounts['months'],
            'prepay_mode' => PrepayMode::from((string) $data['prepayMode']),
        ], $id !== null ? $this->user()->loans()->findOrFail($id) : null, ($amounts['dueDay'] ?? '') === '' ? null : (int) $amounts['dueDay']);

        unset($this->loans);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Loan saved.'));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public function prepayData(int $loanId): array
    {
        $loan = $this->user()->loans()->findOrFail($loanId);
        $pocket = $this->user()->pockets()->where('loan_id', $loan->id)->first();

        return [
            'loanId' => $loan->id,
            'loanName' => $loan->name,
            'pocketId' => $pocket?->id,
            'amounts' => ['prepay' => $this->input($pocket?->prepay_step !== null ? min($pocket->prepay_step, max(0, $pocket->balance)) : null)],
        ];
    }

    /**
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function prepay(int $loanId, string $amount, ?int $pocketId, RecordPrepayment $recordPrepayment): array
    {
        $user = $this->user();
        $value = $this->parse($amount);

        if ($value === null || $value <= 0) {
            return ['ok' => false, 'errors' => ['prepay' => __('Enter a valid amount.')]];
        }

        $loan = $user->loans()->findOrFail($loanId);
        $pocket = $pocketId !== null ? $user->pockets()->findOrFail($pocketId) : null;

        try {
            $recordPrepayment->handle($user, $loan, $value, $pocket);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => ['prepay' => collect($exception->errors())->flatten()->first()]];
        }

        unset($this->loans, $this->pockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Prepayment recorded.'), subtitle: __('New installment: :amount', ['amount' => money($loan->refresh()->monthlyPayment())]));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return Collection<int, Pocket>
     */
    #[Computed]
    public function pockets(): Collection
    {
        return $this->user()->pockets()->with(['loan', 'budgetLines'])->orderBy('sort')->get();
    }

    /**
     * @return Collection<int, Pocket>
     */
    #[Computed]
    public function archivedPockets(): Collection
    {
        return $this->user()->pockets()->onlyTrashed()->latest('deleted_at')->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    #[Computed]
    public function loans(): Collection
    {
        return $this->user()->loans()->with('pockets')->orderByDesc('principal_balance')->get();
    }

    /**
     * Categories a pocket can pay a spending for: the everyday (variable) ones only.
     *
     * @return Collection<int, \App\Models\Category>
     */
    #[Computed]
    public function spendCategories(): Collection
    {
        return $this->user()->categories()->where('type', LineType::Variable)->orderBy('sort')->get();
    }

    /**
     * The category picked first when paying from a pocket: the one used last for that, else the last spending's.
     */
    #[Computed]
    public function lastSpendCategoryId(): ?int
    {
        $ids = $this->spendCategories->pluck('id');
        $last = fn (bool $fromPocket): ?int => $this->user()->transactions()
            ->whereIn('category_id', $ids)
            ->when($fromPocket, fn ($query) => $query->whereNotNull('pocket_id'))
            ->latest('occurred_on')->latest('id')
            ->value('category_id');

        return $last(true) ?? $last(false);
    }

    #[Computed]
    public function currency(): Currency
    {
        return $this->user()->settings()->currency;
    }

    /**
     * Expected installment (with insurance) after the next prepayment from the loan's pocket.
     */
    public function nextInstallment(Loan $loan, Pocket $pocket): ?int
    {
        if ($pocket->prepay_step === null || $loan->principal_balance <= 0 || $loan->installment <= 0) {
            return null;
        }

        $after = app(LoanCalculator::class)->afterPrepayment(
            new LoanState($loan->principal_balance, $loan->installment, $loan->remaining_months),
            min($pocket->prepay_step, $loan->principal_balance),
            PrepayMode::ReduceInstallment,
            $loan->thm,
        );

        return $after->installment + $loan->insurance;
    }

    public function monthlyDeposit(Pocket $pocket): int
    {
        return (int) $pocket->budgetLines->sum('amount');
    }

    private function input(?int $amount): string
    {
        return $amount === null ? '' : str_replace('.', ',', Money::toInput($amount, $this->currency));
    }

    private function parse(mixed $value): ?int
    {
        return is_scalar($value) && (string) $value !== '' ? Money::parse((string) $value, $this->currency) : null;
    }

    private function moneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && $this->parse($value) === null) {
                $fail(__('Enter a valid amount.'));
            }
        };
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $pockets = $this->pockets;
    $featured = $pockets->first(fn ($p) => $p->prepay_step !== null && ! $p->is_reserve);
    $reserve = $pockets->first(fn ($p) => $p->is_reserve);
    // The prepayment pocket first, then the reserve, then the rest in their own order.
    $ordered = collect([$featured, $reserve])->filter()->concat($pockets->reject(fn ($p) => $p->is($featured) || $p->is($reserve)))->values();
    $loans = $this->loans;
    $decimals = $this->currency->decimals();
@endphp

@php
    $coverAmount = (int) request()->query('fedezes', 0);
    $cover = $coverAmount > 0 && $reserve ? ['pocketId' => $reserve->id, 'amount' => str_replace('.', ',', \App\Support\Money::toInput(min($coverAmount, max(0, $reserve->balance)), $this->currency))] : null;
@endphp
@php
    // ?hitel=ID opens that loan, ?hitel=uj a new one (links from the plan's repayment section).
    $loanQuery = request()->query('hitel');
    $openLoan = $loanQuery === 'uj' ? 'new' : (is_numeric($loanQuery) && $loans->contains('id', (int) $loanQuery) ? (int) $loanQuery : null);
    // Opened from the plan: closing the loan sheet (save or cancel) goes back there.
    $returnTo = $openLoan !== null && request()->query('vissza') === 'terv' ? route('plan') : null;
@endphp
<div x-data="pocketsPage({ decimals: {{ $decimals }}, locale: @js(str_replace('_', '-', app()->getLocale())), cover: @js($cover), openLoan: @js($openLoan), returnTo: @js($returnTo), lastSpendCategory: @js($this->lastSpendCategoryId) })">
    <x-ui.page-header :title="__('Pockets')" :subtitle="__('Saved in total: :amount', ['amount' => money((int) $pockets->sum('balance'))])">
        <x-slot name="actions">
            <x-ui.button variant="secondary" size="sm" icon="add" x-on:click="sheet = 'new'" data-test="add"><span class="max-[360px]:sr-only">{{ __('Add') }}</span></x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <section class="mt-[18px]" data-test="pockets-section">
        @if ($pockets->isEmpty())
            <x-ui.empty-state icon="savings" :title="__('No pockets yet')" class="mx-4 mb-3" data-test="pockets-empty">
                {{ __('Pockets collect money for a goal: a reserve, a prepayment, a holiday. Link a plan line to a pocket and it fills up at every month end.') }}
                <x-slot:actions>
                    <x-ui.button size="md" icon="add" x-on:click="openPocket(null)" data-test="empty-new-pocket">{{ __('New pocket') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @endif

        @foreach ($ordered as $pocket)
            @php
                $isFeatured = $pocket->is($featured);
                $isReserve = $pocket->is($reserve);
                $goal = $isFeatured ? $pocket->prepay_step : ($isReserve ? $pocket->target_amount : ($pocket->target_amount ?? $pocket->prepay_step));
                $deposit = $this->monthlyDeposit($pocket);
                $missing = $isFeatured ? max(0, $pocket->prepay_step - $pocket->balance) : 0;
                $icon = $isFeatured ? 'trending_down' : ($isReserve ? 'shield' : ($pocket->is_shared ? 'group' : 'savings'));
                // At most two short muted lines under the name: what it is for, and what comes in every month.
                $notes = array_filter([
                    $isFeatured ? ($pocket->loan ? __('Prepayment · :loan', ['loan' => $pocket->loan->name]) : __('Saving for a prepayment')) : null,
                    $isReserve ? __('filled from the month-end leftover') : null,
                    // The plan's monthly saving moves into the pocket at closing (PeriodCloser), not on payday.
                    $deposit > 0 ? __('+:amount/month at closing', ['amount' => money($deposit)]) : null,
                ]);
            @endphp
            <div class="mx-4 mb-3 rounded-card bg-surface" wire:key="pocket-{{ $pocket->id }}" data-test="pocket-card-{{ $pocket->id }}">
                <button type="button" x-on:click="openPocket({{ $pocket->id }})" class="focus-ring block w-full rounded-card px-[18px] pb-3.5 pt-[18px] text-left" data-test="pocket-{{ $pocket->id }}">
                    <span class="flex items-center gap-3">
                        <x-ui.icon-tile :icon="$icon" tone="accent" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold leading-snug">{{ $pocket->name }}</span>
                            @foreach ($notes as $note)<span class="num mt-0.5 block truncate text-[13px] leading-snug text-muted" data-test="pocket-note">{{ $note }}</span>@endforeach
                        </span>
                        <x-ui.icon name="chevron_right" :size="18" class="text-faint" />
                    </span>
                    <x-ui.amount :value="$pocket->balance" :of="$goal ?: null" :size="$isFeatured || $isReserve ? 'lg' : 'md'" class="mt-3" data-test="pocket-card-balance" />
                    @if ($goal)
                        <x-ui.bar :label="$pocket->name" :value="$pocket->balance / max(1, $goal)" :height="8" :tone="$isFeatured && $pocket->hasReachedPrepayStep() ? 'warn' : 'accent'" class="mt-2.5" />
                    @endif
                    @if ($isFeatured)
                        <span class="num mt-2 flex justify-between text-xs text-muted">
                            @if ($missing > 0)
                                <span>{{ __(':amount to go', ['amount' => money($missing)]) }}</span>
                                @if ($deposit > 0)<span>{{ trans_choice('{1} about :count closing|[2,*] about :count closings', (int) ceil($missing / $deposit), ['count' => (int) ceil($missing / $deposit)]) }}</span>@endif
                            @else
                                <span class="font-medium text-warn">{{ __('Ready to prepay') }}</span>
                            @endif
                        </span>
                    @endif
                </button>
                <div class="grid grid-cols-2 gap-2 px-[18px] pb-[18px]">
                    <x-ui.button variant="secondary" size="md" icon="add" x-on:click="openPocket({{ $pocket->id }}, 'deposit')" data-test="pocket-deposit-{{ $pocket->id }}">{{ __('Deposit') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="md" icon="remove" x-on:click="openPocket({{ $pocket->id }}, 'budget')" :disabled="$pocket->balance <= 0" class="disabled:text-muted" data-test="pocket-withdraw-{{ $pocket->id }}">{{ __('Withdraw') }}</x-ui.button>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Loans: their own section, outlined cards (a debt, not a saving) --}}
    <section class="mt-3" data-test="loans-section">
        <x-ui.section-header :title="__('Loans')" :subtitle="__('What you owe and what you pay every month.')">
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" icon="add" x-on:click="openLoan(null)" data-test="add-loan"><span class="max-[360px]:sr-only">{{ __('New loan') }}</span></x-ui.button>
            </x-slot:actions>
        </x-ui.section-header>

        @if ($loans->isEmpty())
            <div class="mx-4 mb-3 rounded-card bg-surface px-[18px] py-1" data-test="loans-empty">
                <x-ui.empty-state compact icon="account_balance" :title="__('No loans yet')">{{ __('A loan you add gets a repayment line in the plan.') }}</x-ui.empty-state>
            </div>
        @endif

        @foreach ($loans as $loan)
            @php
                $fund = $loan->pockets->first(fn ($p) => $p->prepay_step !== null);
                $next = $fund ? $this->nextInstallment($loan, $fund) : null;
                // Once the prepayment pocket reached its step, recording the prepayment is the card's main action.
                $prepayReady = $fund !== null && $fund->hasReachedPrepayStep();
            @endphp
            <div class="mx-4 mb-3 rounded-card border border-line p-[18px]" wire:key="loan-{{ $loan->id }}" data-test="loan-{{ $loan->id }}">
                <button type="button" x-on:click="openLoan({{ $loan->id }})" class="focus-ring flex w-full items-center gap-3 rounded-control text-left" data-test="edit-loan-{{ $loan->id }}">
                    <x-ui.icon-tile icon="account_balance" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold leading-snug">{{ $loan->name }}</span>
                        @if ($loan->lender)<span class="mt-0.5 block truncate text-[13px] leading-snug text-muted" data-test="loan-lender">{{ $loan->lender }}</span>@endif
                    </span>
                    <x-ui.icon name="chevron_right" :size="18" class="text-faint" />
                </button>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="min-w-0"><div class="text-xs text-muted">{{ __('Remaining principal') }}</div><x-ui.amount :value="$loan->principal_balance" class="mt-1" /></div>
                    <div class="min-w-0"><div class="text-xs text-muted">{{ __('Monthly installment') }}</div><x-ui.amount :value="$loan->monthlyPayment()" class="mt-1" /></div>
                </div>
                @if ($next !== null && $next < $loan->monthlyPayment())
                    <div class="mt-4 rounded-group border border-accent/22 bg-accent/10 px-4 py-3.5">
                        <div class="text-[13px] leading-snug text-ink-2">{{ __('Expected installment after the next prepayment:') }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-2.5">
                            <x-ui.amount :value="$loan->monthlyPayment()" size="sm" class="text-[15px] text-muted line-through" />
                            <x-ui.icon name="arrow_forward" :size="18" class="text-accent" />
                            <span class="num text-2xl font-semibold text-accent">~{{ money($next) }}</span>
                        </div>
                        <div class="mt-1.5 text-xs text-muted">{{ __('when :pocket reaches :step', ['pocket' => $fund->name, 'step' => money($fund->prepay_step)]) }}</div>
                    </div>
                @endif
                <x-ui.button :variant="$prepayReady ? 'tonal' : 'secondary'" size="md" icon="trending_down" x-on:click="openPrepay({{ $loan->id }})" class="mt-3 w-full" data-test="prepay-loan-{{ $loan->id }}">{{ __('Record prepayment') }}</x-ui.button>
            </div>
        @endforeach
    </section>

    @if ($this->archivedPockets->isNotEmpty())
        <details class="group mx-4 mb-3 mt-2" data-test="archived-pockets">
            <summary class="flex cursor-pointer list-none items-center justify-between px-3.5 py-2 text-[13px] font-semibold text-muted">
                {{ __('Archived pockets') }} · {{ $this->archivedPockets->count() }}
                <x-ui.icon name="expand_more" :size="18" class="transition group-open:rotate-180" />
            </summary>
            <div class="rounded-card bg-surface px-[18px]">
                @foreach ($this->archivedPockets as $archived)
                    <div wire:key="archived-{{ $archived->id }}" @class(['flex items-center gap-3 py-3', 'border-b border-line' => ! $loop->last])>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] text-ink-2">{{ $archived->name }}</span>
                            <x-ui.amount :value="$archived->balance" size="sm" class="text-xs text-muted" />
                        </span>
                        <x-ui.button size="sm" variant="secondary" wire:click="restorePocket({{ $archived->id }})" data-test="restore-pocket-{{ $archived->id }}">{{ __('Restore') }}</x-ui.button>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    {{-- Add: choose a pocket or a loan --}}
    <x-ui.sheet show="sheet === 'new'" close="sheet = null" :label="__('Add')" :full="false">
        <div class="divide-y divide-line overflow-hidden rounded-group bg-surface-2">
            @foreach ([
                ['new-pocket', 'openPocket(null)', 'savings', 'accent', __('New pocket'), __('Reserve, prepayment or any goal')],
                ['new-loan', 'openLoan(null)', 'account_balance', 'default', __('New loan'), __('Principal, installment and APR')],
            ] as [$test, $action, $tileIcon, $tone, $label, $description])
                <button type="button" x-on:click="{{ $action }}" class="focus-ring flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left" data-test="{{ $test }}">
                    <x-ui.icon-tile :icon="$tileIcon" :tone="$tone" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px] font-medium">{{ $label }}</span>
                        <span class="mt-0.5 block truncate text-[13px] text-muted">{{ $description }}</span>
                    </span>
                    <x-ui.icon name="chevron_right" :size="18" class="text-faint" />
                </button>
            @endforeach
        </div>
    </x-ui.sheet>

    {{-- Pocket: money movements (numpad always at hand) and settings --}}
    <x-ui.sheet show="sheet === 'pocket'" close="sheet = null" title="title()" data-test="pocket-sheet">
        <template x-if="form.id">
            <div>
                <div class="flex items-baseline justify-between gap-3 px-1.5 pb-2 pt-1">
                    <span class="text-sm text-muted">{{ __('Balance') }}</span>
                    <span class="flex min-w-0 items-baseline gap-1.5">
                        <x-ui.amount bind="money(form.balance)" size="md" data-test="pocket-balance" />
                        <span class="num truncate text-sm text-muted" x-show="form.target" x-text="'/ ' + money(form.target) + ' ' + @js(user_currency()->symbol())"></span>
                    </span>
                </div>
                <x-ui.tabs id="pocket" model="pocketTab" :options="['money' => __('Move money'), 'settings' => __('Settings')]" />
            </div>
        </template>

        <div id="pocket-panel-money" role="tabpanel" aria-labelledby="pocket-tab-money" x-show="form.id && pocketTab === 'money'" class="pt-3">
            <template x-if="moveMode === 'spend'">
                <div x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest' }))">
                    <x-ui.form-group>
                        <x-ui.select-row :label="__('Category')" x-model.number="spendCategory" data-test="spend-category">
                            <option value="">{{ __('Choose a category') }}</option>
                            @foreach ($this->spendCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                        </x-ui.select-row>
                        <x-ui.text-row :label="__('Note')" x-model="spendNote" maxlength="255" :placeholder="__('Optional')" />
                    </x-ui.form-group>
                    <p class="px-1.5 pt-2 text-xs text-danger" x-show="errors.category" x-text="errors.category" role="alert"></p>
                </div>
            </template>

            {{-- Under the numpad on a short phone there is no room for the list: it waits behind its title. --}}
            <template x-if="form.movements?.length">
                <section :class="moveMode === 'spend' ? 'mt-4' : 'mt-1'">
                    <button type="button" x-on:click="showMovements = ! showMovements" :aria-expanded="showMovements ? 'true' : 'false'" aria-controls="pocket-movements"
                            class="focus-ring flex min-h-11 w-full items-center justify-between gap-3 rounded-control px-1.5 text-left text-[13px] font-semibold text-muted" data-test="movements-toggle">
                        <span>{{ __('Recent movements') }} <span class="num font-normal" x-text="'· ' + form.movements.length"></span></span>
                        <x-ui.icon name="expand_more" :size="20" class="text-faint transition" ::class="showMovements && 'rotate-180'" />
                    </button>
                    <div id="pocket-movements" x-show="showMovements" class="divide-y divide-line overflow-hidden rounded-group bg-surface-2" data-test="movements">
                        <template x-for="(movement, index) in form.movements" :key="index">
                            <div class="num flex min-h-11 items-center justify-between gap-3 px-4 py-2.5 text-[13px]">
                                <span class="truncate text-muted" x-text="movement.date + ' · ' + movement.label"></span>
                                <span class="shrink-0 font-semibold" :class="movement.negative ? 'text-danger' : 'text-ink'" x-text="movement.amount" data-test="movement-amount"></span>
                            </div>
                        </template>
                    </div>
                </section>
            </template>
        </div>

        <div id="pocket-panel-settings" :role="form.id && 'tabpanel'" :aria-labelledby="form.id && 'pocket-tab-settings'" x-show="! form.id || pocketTab === 'settings'" :class="form.id && 'pt-3'">
            <x-ui.form-group :title="__('Basics')">
                <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" :placeholder="__('e.g. Holiday')" error="name" data-test="pocket-name" />
                <x-ui.amount-row name="target" :label="__('Target')" fallback="" error="target" data-test="field-target" />
            </x-ui.form-group>
            <x-ui.form-group :title="__('Loan prepayment')" :hint="__('When the pocket reaches the step, the loan card shows how much the installment drops.')">
                <x-ui.select-row :label="__('Prepays loan')" x-model.number="form.loanId">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($loans as $loan)<option value="{{ $loan->id }}">{{ $loan->name }}</option>@endforeach
                </x-ui.select-row>
                <x-ui.amount-row name="step" :label="__('Prepayment step')" fallback="" error="step" x-show="form.loanId" />
            </x-ui.form-group>
            <x-ui.form-group :title="__('Role')">
                <x-ui.switch-row model="form.isReserve" :label="__('This is the reserve')" :hint="__('At closing the leftover goes here first, a deficit comes from here.')" />
                <x-ui.switch-row model="form.isShared" :label="__('Shared pocket')" :hint="__('Only a marker.')" />
            </x-ui.form-group>
        </div>

        <x-slot:danger>
            <x-ui.button variant="danger" size="md" icon="delete" x-show="form.id && pocketTab === 'settings'" x-on:click="removePocket()" class="w-full" data-test="delete-pocket">{{ __('Delete pocket') }}</x-ui.button>
        </x-slot:danger>

        <x-slot:footer>
            <div x-show="form.id && pocketTab === 'money'" data-test="money-pad">
                {{--
                    The three ways to move money as a radio list: icon, title and one line on what it does.
                    It folds to the chosen one, so the amount, the numpad and the button fit a short phone;
                    tapping the chosen one opens the others.
                --}}
                @php
                    $modes = [
                        'deposit' => ['add', __('Deposit'), __('The month’s leftover goes down by the same amount.')],
                        'budget' => ['remove', __('Withdraw to the monthly budget'), __('Counts as extra income this month.')],
                        'spend' => ['receipt_long', __('Pay a spending'), __('The pocket pays it, the budget stays untouched.')],
                    ];
                @endphp
                <div role="radiogroup" aria-label="{{ __('What do you want to do?') }}" class="divide-y divide-line overflow-hidden rounded-group bg-surface-2" data-test="move-modes">
                    @foreach ($modes as $mode => [$modeIcon, $modeLabel, $modeHint])
                        <button type="button" role="radio" x-show="modesOpen || moveMode === @js($mode)"
                                x-on:click="pickMode(@js($mode))" :aria-checked="moveMode === @js($mode) ? 'true' : 'false'" :tabindex="moveMode === @js($mode) ? 0 : -1"
                                x-on:keydown.down.prevent="stepMode(1)" x-on:keydown.right.prevent="stepMode(1)" x-on:keydown.up.prevent="stepMode(-1)" x-on:keydown.left.prevent="stepMode(-1)"
                                @if ($mode !== 'deposit') :disabled="form.balance <= 0" @endif
                                class="focus-ring flex min-h-14 w-full items-center gap-3 px-3 py-2 text-left transition-colors disabled:opacity-40" data-test="mode-{{ $mode }}">
                            <x-ui.icon-tile :icon="$modeIcon" :size="36" ::class="moveMode === '{{ $mode }}' && '!bg-accent/12 !text-accent'" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[15px] font-medium">{{ $modeLabel }}</span>
                                <span class="mt-px block text-xs leading-snug text-pretty text-muted" data-test="mode-hint">{{ $modeHint }}</span>
                            </span>
                            <span class="flex size-5 shrink-0 items-center justify-center" aria-hidden="true">
                                <template x-if="! modesOpen"><x-ui.icon name="expand_more" :size="20" class="text-muted" /></template>
                                <template x-if="modesOpen && moveMode === @js($mode)"><x-ui.icon name="check_circle" :size="20" fill class="text-accent" /></template>
                                <template x-if="modesOpen && moveMode !== @js($mode)"><span class="size-[18px] rounded-full border-[1.5px] border-faint"></span></template>
                            </span>
                        </button>
                    @endforeach
                </div>
                <p class="mt-2 text-center text-xs text-muted" x-show="form.balance <= 0" data-test="empty-note">{{ __('The pocket is empty, there is nothing to take out.') }}</p>
                <div class="mt-2 text-center" :class="! positive('move') && 'text-muted'"><x-ui.amount bind="display('move')" size="lg" data-test="move-amount" /></div>
                <p class="mt-1 text-center text-xs text-danger" x-show="errors.move" x-text="errors.move" role="alert"></p>
                {{-- Lower keys on a very short phone (320×568), so the balance and the tabs stay on screen above. --}}
                <x-ui.numpad :decimal="$decimals > 0" enter="move()" class="mt-2 [@media(max-height:600px)]:[&_button]:min-h-9" />
                <x-ui.button x-on:click="move()" ::disabled="saving || ! positive('move')" class="mt-3 w-full" data-test="pocket-move">
                    <span x-text="{ deposit: @js(__('Deposit')), budget: @js(__('Withdraw to the monthly budget')), spend: @js(__('Pay from the pocket')) }[moveMode]"></span>
                </x-ui.button>
            </div>
            <x-ui.button x-show="! form.id || pocketTab === 'settings'" x-on:click="submit()" ::disabled="saving" class="w-full" data-test="sheet-save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><div x-show="! form.id || pocketTab === 'settings'"><x-ui.amount-pad :decimal="$decimals > 0" /></div></x-slot:pad>
    </x-ui.sheet>

    {{-- Loan --}}
    <x-ui.sheet show="sheet === 'loan'" close="sheet = null" title="title()" data-test="loan-sheet">
        <x-slot:intro>{{ __('You find these on your loan statement.') }}</x-slot:intro>
        <x-ui.form-group :title="__('Basics')">
            <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" :placeholder="__('e.g. Home loan')" error="name" data-test="loan-name" />
            <x-ui.text-row :label="__('Lender')" x-model="form.lender" maxlength="80" :placeholder="__('Optional')" />
        </x-ui.form-group>
        <x-ui.form-group :title="__('Monthly payment')">
            <x-ui.amount-row name="installment" fallback="" :label="__('Installment')" error="installment" data-test="loan-installment" />
            <x-ui.amount-row name="insurance" fallback="" :label="__('Insurance')" error="insurance" data-test="loan-insurance" />
            <x-ui.day-field :label="__('Due day')" value="fields.dueDay" pick="fields.dueDay = day === null ? '' : String(day)" :hint="__('Day of the month; you get a reminder that morning')" error="dueDay" data-test="loan-due-day" />
        </x-ui.form-group>
        <x-ui.form-group :title="__('Balance')">
            <x-ui.amount-row name="principal" fallback="" :label="__('Outstanding principal')" :hint="__('Without interest')" error="principal" data-test="loan-principal" />
            <x-ui.amount-row name="thm" fallback="" :label="__('APR (%)')" error="thm" data-test="loan-thm" />
            <x-ui.amount-row name="months" fallback="" :label="__('Months left')" error="months" data-test="loan-months" />
        </x-ui.form-group>
        <x-ui.form-group :title="__('After a prepayment')">
            <div class="p-3">
                <x-ui.segmented model="form.prepayMode" :options="collect(PrepayMode::cases())->mapWithKeys(fn ($mode) => [$mode->value => $mode->label()])->all()" />
            </div>
        </x-ui.form-group>
        <x-slot:footer>
            <x-ui.button x-on:click="submit()" ::disabled="saving" class="w-full" data-test="sheet-save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="true" /></x-slot:pad>
    </x-ui.sheet>

    {{-- Prepayment --}}
    <x-ui.sheet show="sheet === 'prepay'" close="sheet = null" title="title()" :full="false" data-test="prepay-sheet">
        <x-slot:intro><span class="block font-medium text-ink-2" x-text="form.loanName"></span>{{ __('If you paid it from a pocket, its balance goes down too.') }}</x-slot:intro>
        <div class="py-2 text-center" :class="! positive('prepay') && 'text-muted'"><x-ui.amount bind="display('prepay')" size="xl" /></div>
        <div class="mb-2 text-center text-xs text-danger" x-show="errors.prepay" x-text="errors.prepay" role="alert"></div>
        <x-ui.form-group>
            <x-ui.select-row :label="__('From pocket')" x-model.number="form.pocketId">
                <option value="">{{ __('Not from a pocket') }}</option>
                @foreach ($pockets as $pocket)<option value="{{ $pocket->id }}">{{ $pocket->name }} ({{ money($pocket->balance) }})</option>@endforeach
            </x-ui.select-row>
        </x-ui.form-group>
        <x-ui.numpad :decimal="$decimals > 0" class="mt-3" />
        <x-slot:footer>
            <x-ui.button x-on:click="submit()" ::disabled="saving || ! positive('prepay')" class="w-full" data-test="sheet-save">{{ __('Record prepayment') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
</div>

@script
<script>
    Alpine.data('pocketsPage', ({ decimals, locale, cover, openLoan, returnTo, lastSpendCategory }) => ({
        sheet: null,
        moveMode: 'deposit',
        /** The money modes fold to the chosen one; tapping it opens the others. */
        modesOpen: false,
        /** Recent movements: open where the screen is tall enough next to the numpad. */
        showMovements: false,
        pocketTab: 'money',
        spendCategory: '',
        spendNote: '',
        form: {},
        errors: {},
        saving: false,
        ...window.amountFields({ decimals, locale, rules: { months: { decimals: 0, symbol: @js(__('months')) }, thm: { decimals: 3, symbol: '%' } } }),
        moneyFormatter: new Intl.NumberFormat(locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping: 'always' }),

        init() {
            // On the money tab the sheet's own numpad types straight into the amount.
            this.$watch('pocketTab', () => { if (this.sheet === 'pocket') this.active = this.moneyField() })
            if (openLoan !== null) {
                this.openLoan(openLoan === 'new' ? null : openLoan).then(() => {
                    if (! returnTo) return
                    const stop = this.$watch('sheet', open => {
                        if (open) return
                        stop?.()
                        // Let the sheet slide away, then go back where the loan was opened from.
                        setTimeout(() => Livewire.navigate(returnTo), 200)
                    })
                })
                history.replaceState(null, '', location.pathname)
            }
            if (cover?.pocketId) {
                this.openPocket(cover.pocketId, 'budget').then(() => { this.fields.move = String(cover.amount) })
                history.replaceState(null, '', location.pathname)
            }
        },

        title() {
            return {
                new: @js(__('Add')),
                pocket: this.form.id ? this.form.name : @js(__('New pocket')),
                loan: this.form.id ? this.form.name : @js(__('New loan')),
                prepay: @js(__('Prepayment')),
            }[this.sheet] ?? ''
        },

        money(minor) { return this.moneyFormatter.format(minor / Math.pow(10, this.decimals)) },

        /** True when the typed amount is more than zero: the primary button stays off until then. */
        positive(name) { return parseFloat(String(this.fields?.[name] ?? '').replace(',', '.')) > 0 },

        /** The amount field the always-visible numpad types into (none on the settings tab). */
        moneyField() { return this.form.id && this.pocketTab === 'money' ? 'move' : null },

        amountGuard() { return ! this.saving },

        open(sheet, data, active) {
            this.form = data
            this.fields = { ...data.amounts }
            this.active = active
            this.errors = {}
            this.sheet = sheet
        },
        /** mode: 'deposit' | 'budget' | 'spend' — the card buttons open the sheet with it preset. */
        async openPocket(id, mode = 'deposit') {
            const data = await $wire.pocketData(id)
            this.moveMode = data.balance > 0 ? mode : 'deposit'
            this.modesOpen = false
            this.showMovements = window.innerHeight > 700
            this.pocketTab = 'money'
            this.spendCategory = lastSpendCategory ?? ''
            this.spendNote = ''
            this.open('pocket', data, data.id ? 'move' : null)
        },
        /** A tap on the chosen mode opens the list; a tap on another picks it and folds the list. */
        pickMode(mode) {
            if (! this.modesOpen && mode === this.moveMode) { this.modesOpen = true; return }
            this.moveMode = mode
            this.modesOpen = false
        },
        /** Arrow keys move through the modes that are allowed (only Deposit on an empty pocket). */
        stepMode(step) {
            const modes = this.form.balance > 0 ? ['deposit', 'budget', 'spend'] : ['deposit']
            const next = modes[(modes.indexOf(this.moveMode) + step + modes.length) % modes.length]
            this.moveMode = next
            this.$nextTick(() => document.querySelector(`[data-test="mode-${next}"]`)?.focus())
        },
        async openLoan(id) { const data = await $wire.loanData(id); this.open('loan', data, null) },
        async openPrepay(id) { const data = await $wire.prepayData(id); this.open('prepay', data, 'prepay') },

        async move() {
            if (this.saving || ! this.positive('move')) return
            this.saving = true
            try {
                const amount = this.fields.move || ''
                const result = this.moveMode === 'spend'
                    ? await $wire.payFromPocket(this.form.id, amount, this.spendCategory || null, this.spendNote || null)
                    : await $wire.movePocketMoney(this.form.id, this.moveMode === 'deposit' ? 1 : -1, amount, null, true)
                this.errors = result?.errors ?? {}
                if (result?.ok) { this.sheet = null; this.spendNote = '' }
            } finally {
                this.saving = false
            }
        },
        async removePocket() {
            if (! await window.appConfirm({ title: @js(__('Delete this pocket?')), body: @js(__('It disappears from Pockets, the plan and closing, and no more money goes into it. Its monthly saving is removed from the plan. If the month-end leftover went here, closing no longer moves it anywhere: it stays on your bank account, and you can pick a new place at closing. Past months stay as they were: its deposits, withdrawals and the spending it paid for are kept. Its balance is not moved; you can restore the pocket under Archived pockets, but its monthly saving has to be added again.')), confirm: @js(__('Delete')), danger: true })) return
            await $wire.deletePocket(this.form.id)
            this.sheet = null
        },
        async submit() {
            if (this.saving) return
            this.saving = true
            try {
                let result
                if (this.sheet === 'pocket') result = await $wire.savePocket({ ...this.form, amounts: { ...this.fields } })
                else if (this.sheet === 'loan') result = await $wire.saveLoan({ ...this.form, amounts: { ...this.fields } })
                else if (this.sheet === 'prepay') result = await $wire.prepay(this.form.loanId, this.fields.prepay || '', this.form.pocketId || null)
                this.errors = result?.errors ?? {}
                if (result?.ok) this.sheet = null
            } finally {
                this.saving = false
            }
        },
    }))
</script>
@endscript
