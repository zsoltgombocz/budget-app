<?php

use App\Actions\Budget\DeletePocket;
use App\Actions\Budget\RestorePocket;
use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\PayFromPocket;
use App\Enums\LineType;
use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\SaveLoan;
use App\Enums\Currency;
use App\Enums\PeriodMode;
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
                    'amount' => ($movement->amount > 0 ? '+' : '').money($movement->amount),
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
     * @return array{ok: bool, errors: array<string, string>}
     */
    /**
     * Deposit (direction 1) or withdraw (-1). A withdrawal tops up this period's budget
     * unless $toBudget is false (money simply leaves the pocket).
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
            $movePocketMoney->handle($this->user(), $pocket, ($direction < 0 ? -1 : 1) * $value, $note, $direction < 0 && $toBudget);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => ['move' => (string) collect($exception->errors())->flatten()->first()]];
        }

        unset($this->pockets);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast',
            title: $direction < 0 ? __('Withdrawn: :amount', ['amount' => money($value)]) : __('Deposited: :amount', ['amount' => money($value)]),
            subtitle: $direction < 0 && $toBudget ? __('Added to this period’s budget.') : null,
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
     * Categories a pocket can pay for.
     *
     * @return Collection<int, \App\Models\Category>
     */
    #[Computed]
    public function spendCategories(): Collection
    {
        return $this->user()->categories()->whereIn('type', [LineType::Variable, LineType::Sinking])->orderBy('sort')->get();
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
    $perPeriod = auth()->user()->settings()->period_mode === PeriodMode::Payday ? __('per payday') : __('per month');
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
<div x-data="pocketsPage({ decimals: {{ $decimals }}, locale: @js(str_replace('_', '-', app()->getLocale())), cover: @js($cover), openLoan: @js($openLoan), returnTo: @js($returnTo) })">
    <x-ui.page-header :title="__('Pockets')" :subtitle="__('Saved in total: :amount', ['amount' => money((int) $pockets->sum('balance'))])">
        <x-slot name="actions">
            <x-ui.button variant="secondary" size="sm" icon="add" x-on:click="sheet = 'new'" data-test="add">{{ __('Add') }}</x-ui.button>
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
                $notes = array_filter([
                    $isFeatured ? ($pocket->loan ? __('Saving to prepay :loan', ['loan' => $pocket->loan->name]) : __('Saving for a prepayment')) : null,
                    $isReserve ? __('filled from the month-end leftover') : null,
                    $deposit > 0 ? '+'.money($deposit).' '.$perPeriod : null,
                ]);
            @endphp
            <div class="mx-4 mb-3 rounded-card bg-surface" wire:key="pocket-{{ $pocket->id }}" data-test="pocket-card-{{ $pocket->id }}">
                <button type="button" x-on:click="openPocket({{ $pocket->id }})" class="focus-ring block w-full rounded-card px-[18px] pb-3.5 pt-[18px] text-left" data-test="pocket-{{ $pocket->id }}">
                    <span class="flex items-center gap-3">
                        <x-ui.icon-tile :icon="$icon" tone="accent" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold">{{ $pocket->name }}</span>
                            @if ($notes !== [])<span class="num mt-0.5 block truncate text-xs text-muted">{{ implode(' · ', $notes) }}</span>@endif
                        </span>
                        <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
                    </span>
                    <x-ui.amount :value="$pocket->balance" :of="$goal ?: null" :size="$isFeatured || $isReserve ? 'lg' : 'md'" class="mt-3.5" />
                    @if ($goal)
                        <x-ui.bar :value="$pocket->balance / max(1, $goal)" :height="8" :tone="$isFeatured && $pocket->hasReachedPrepayStep() ? 'warn' : 'accent'" class="mt-2.5" />
                    @endif
                    @if ($isFeatured)
                        <span class="num mt-2 flex justify-between text-xs text-muted">
                            @if ($missing > 0)
                                <span>{{ __(':amount to go', ['amount' => money($missing)]) }}</span>
                                @if ($deposit > 0)<span>{{ trans_choice('{1} about :count payday|[2,*] about :count paydays', (int) ceil($missing / $deposit), ['count' => (int) ceil($missing / $deposit)]) }}</span>@endif
                            @else
                                <span class="font-medium text-warn">{{ __('Ready to prepay') }}</span>
                            @endif
                        </span>
                    @endif
                </button>
                <div class="grid grid-cols-2 gap-2 px-[18px] pb-[18px]">
                    <x-ui.button variant="secondary" size="md" icon="add" x-on:click="openPocket({{ $pocket->id }}, 'deposit')" data-test="pocket-deposit-{{ $pocket->id }}">{{ __('Deposit') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="md" icon="remove" x-on:click="openPocket({{ $pocket->id }}, 'budget')" :disabled="$pocket->balance <= 0" class="disabled:text-faint" data-test="pocket-withdraw-{{ $pocket->id }}">{{ __('Withdraw') }}</x-ui.button>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Loans: their own section, outlined cards (a debt, not a saving) --}}
    <section class="mt-6" data-test="loans-section">
        <div class="flex items-end justify-between gap-3 px-6 pb-3">
            <div class="min-w-0">
                <h2 class="text-xl font-semibold tracking-[-0.02em]">{{ __('Loans') }}</h2>
                <p class="mt-0.5 text-[13px] leading-snug text-muted">{{ __('What you owe and what you pay every month.') }}</p>
            </div>
            <x-ui.button variant="secondary" size="sm" icon="add" x-on:click="openLoan(null)" class="shrink-0" data-test="add-loan">{{ __('New loan') }}</x-ui.button>
        </div>

        @if ($loans->isEmpty())
            <p class="mx-4 mb-3 rounded-card border border-dashed border-ink/14 px-[18px] py-4 text-sm leading-snug text-muted" data-test="loans-empty">{{ __('No loans yet. A loan you add gets a repayment line in the plan.') }}</p>
        @endif

        @foreach ($loans as $loan)
            @php
                $fund = $loan->pockets->first(fn ($p) => $p->prepay_step !== null);
                $next = $fund ? $this->nextInstallment($loan, $fund) : null;
            @endphp
            <div class="mx-4 mb-3 rounded-card border border-line p-[18px]" wire:key="loan-{{ $loan->id }}" data-test="loan-{{ $loan->id }}">
                <button type="button" x-on:click="openLoan({{ $loan->id }})" class="focus-ring flex w-full items-center gap-3 rounded-xl text-left" data-test="edit-loan-{{ $loan->id }}">
                    <x-ui.icon-tile icon="account_balance" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold">{{ $loan->name }}</span>
                        @if ($loan->lender)<span class="block truncate text-xs text-muted">{{ $loan->lender }}</span>@endif
                    </span>
                    <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
                </button>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div><div class="text-xs text-muted">{{ __('Remaining principal') }}</div><x-ui.amount :value="$loan->principal_balance" class="mt-1" /></div>
                    <div><div class="text-xs text-muted">{{ __('Monthly installment') }}</div><x-ui.amount :value="$loan->monthlyPayment()" class="mt-1" /></div>
                </div>
                @if ($next !== null && $next < $loan->monthlyPayment())
                    <div class="mt-4 rounded-btn border border-accent/22 bg-accent/10 px-4 py-3.5">
                        <div class="text-[13px] leading-snug text-ink-2">{{ __('Expected installment after the next prepayment:') }}</div>
                        <div class="mt-2 flex items-center gap-2.5">
                            <x-ui.amount :value="$loan->monthlyPayment()" size="sm" class="text-[15px] text-muted line-through" />
                            <x-ui.icon name="arrow_forward" :size="18" class="text-accent" />
                            <span class="num text-2xl font-semibold text-accent">~{{ money($next) }}</span>
                        </div>
                        <div class="mt-1.5 text-xs text-muted">{{ __('when :pocket reaches :step', ['pocket' => $fund->name, 'step' => money($fund->prepay_step)]) }}</div>
                    </div>
                @endif
                <x-ui.button variant="secondary" size="md" icon="trending_down" x-on:click="openPrepay({{ $loan->id }})" class="mt-3 w-full" data-test="prepay-loan-{{ $loan->id }}">{{ __('Record prepayment') }}</x-ui.button>
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
        <div class="grid gap-2">
            <button type="button" x-on:click="openPocket(null)" class="focus-ring flex items-center gap-3 rounded-btn bg-surface-2 p-4 text-left" data-test="new-pocket"><x-ui.icon-tile icon="savings" tone="accent" /><span><span class="block font-semibold">{{ __('New pocket') }}</span><span class="block text-xs text-muted">{{ __('Reserve, prepayment or any goal') }}</span></span></button>
            <button type="button" x-on:click="openLoan(null)" class="focus-ring flex items-center gap-3 rounded-btn bg-surface-2 p-4 text-left" data-test="new-loan"><x-ui.icon-tile icon="account_balance" /><span><span class="block font-semibold">{{ __('New loan') }}</span><span class="block text-xs text-muted">{{ __('Principal, installment and APR') }}</span></span></button>
        </div>
    </x-ui.sheet>

    {{-- Pocket: money movements (numpad always at hand) and settings --}}
    <x-ui.sheet show="sheet === 'pocket'" close="sheet = null" title="title()" data-test="pocket-sheet">
        <template x-if="form.id">
            <div>
                <div class="flex items-baseline justify-between gap-3 px-1.5 pb-3 pt-1">
                    <span class="text-sm text-muted">{{ __('Balance') }}</span>
                    <span class="flex items-baseline gap-1.5">
                        <x-ui.amount bind="money(form.balance)" size="md" data-test="pocket-balance" />
                        <span class="num text-sm text-muted" x-show="form.target" x-text="'/ ' + money(form.target) + ' ' + @js(user_currency()->symbol())"></span>
                    </span>
                </div>
                <x-ui.segmented model="pocketTab" :options="['money' => __('Move money'), 'settings' => __('Settings')]" />
            </div>
        </template>

        <div x-show="form.id && pocketTab === 'money'" class="pt-3">
            <div role="radiogroup" aria-label="{{ __('What do you want to do?') }}" class="grid gap-2">
                @foreach ([
                    'deposit' => [__('Deposit'), __('Money into the pocket. The monthly budget does not change.')],
                    'budget' => [__('Withdraw to the monthly budget'), __('The money leaves the pocket and counts as extra income in this period.')],
                    'spend' => [__('Pay a spending from the pocket'), __('The spending is paid by the pocket: it does not use up the monthly budget.')],
                ] as $mode => [$modeLabel, $modeHint])
                    <button type="button" role="radio" x-on:click="moveMode = @js($mode)" :aria-checked="moveMode === @js($mode) ? 'true' : 'false'"
                            class="focus-ring flex w-full items-start gap-3 rounded-2xl border px-3.5 py-2.5 text-left transition-colors"
                            :class="moveMode === @js($mode) ? 'border-accent bg-accent/10' : 'border-transparent bg-surface-2'" data-test="mode-{{ $mode }}">
                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2" :class="moveMode === @js($mode) ? 'border-accent' : 'border-ink/25'" aria-hidden="true">
                            <span class="h-2.5 w-2.5 rounded-full bg-accent" x-show="moveMode === @js($mode)"></span>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-[15px] font-semibold leading-snug">{{ $modeLabel }}</span>
                            <span class="mt-0.5 block text-xs leading-snug text-muted">{{ $modeHint }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

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

            <template x-if="form.movements?.length">
                <section class="mt-5">
                    <h3 class="px-1.5 pb-2 text-[13px] font-semibold text-muted">{{ __('Recent movements') }}</h3>
                    <div class="divide-y divide-line overflow-hidden rounded-2xl bg-surface-2">
                        <template x-for="movement in form.movements">
                            <div class="num flex justify-between gap-2 px-4 py-2.5 text-[13px]"><span class="truncate text-muted" x-text="movement.date + ' · ' + movement.label"></span><span class="shrink-0 font-medium" x-text="movement.amount"></span></div>
                        </template>
                    </div>
                </section>
            </template>
        </div>

        <div x-show="! form.id || pocketTab === 'settings'" :class="form.id && 'pt-3'">
            <x-ui.form-group :title="__('Basics')">
                <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" :placeholder="__('e.g. Holiday')" error="name" data-test="pocket-name" />
                <x-ui.amount-row name="target" :label="__('Target')" error="target" data-test="field-target" />
            </x-ui.form-group>
            <x-ui.form-group :title="__('Loan prepayment')" :hint="__('When the pocket reaches the step, the loan card shows how much the installment drops.')">
                <x-ui.select-row :label="__('Prepays loan')" x-model.number="form.loanId">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($loans as $loan)<option value="{{ $loan->id }}">{{ $loan->name }}</option>@endforeach
                </x-ui.select-row>
                <x-ui.amount-row name="step" :label="__('Prepayment step')" error="step" x-show="form.loanId" />
            </x-ui.form-group>
            <x-ui.form-group :title="__('Role')">
                <x-ui.switch-row model="form.isReserve" :label="__('This is the reserve')" :hint="__('At closing the leftover goes here first, a deficit comes from here.')" />
                <x-ui.switch-row model="form.isShared" :label="__('Shared pocket')" :hint="__('Only a marker.')" />
            </x-ui.form-group>
            <div class="mt-6 text-center" x-show="form.id">
                <button type="button" x-on:click="removePocket()" class="focus-ring h-11 rounded-xl px-4 text-[15px] font-medium text-danger" data-test="delete-pocket">{{ __('Delete pocket') }}</button>
            </div>
        </div>

        <x-slot:footer>
            <div x-show="form.id && pocketTab === 'money'" data-test="money-pad">
                <div class="text-center" :class="! positive('move') && 'text-faint'"><x-ui.amount bind="display('move')" size="lg" data-test="move-amount" /></div>
                <p class="mt-1 text-center text-xs text-danger" x-show="errors.move" x-text="errors.move" role="alert"></p>
                <x-ui.numpad :decimal="$decimals > 0" class="mt-2" />
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
            <x-ui.amount-row name="installment" :label="__('Installment')" error="installment" data-test="loan-installment" />
            <x-ui.amount-row name="insurance" :label="__('Insurance')" error="insurance" data-test="loan-insurance" />
            <x-ui.amount-row name="dueDay" :label="__('Due day')" :hint="__('Day of the month; you get a reminder that morning')" error="dueDay" data-test="loan-due-day" />
        </x-ui.form-group>
        <x-ui.form-group :title="__('Balance')">
            <x-ui.amount-row name="principal" :label="__('Outstanding principal')" :hint="__('Without interest')" error="principal" data-test="loan-principal" />
            <x-ui.amount-row name="thm" :label="__('APR (%)')" error="thm" data-test="loan-thm" />
            <x-ui.amount-row name="months" :label="__('Months left')" error="months" data-test="loan-months" />
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
        <x-slot:intro>{{ __('If you paid it from a pocket, its balance goes down too.') }}</x-slot:intro>
        <div class="py-2 text-center" :class="! positive('prepay') && 'text-faint'"><x-ui.amount bind="display('prepay')" size="xl" /></div>
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
    Alpine.data('pocketsPage', ({ decimals, locale, cover, openLoan, returnTo }) => ({
        sheet: null,
        moveMode: 'deposit',
        pocketTab: 'money',
        spendCategory: '',
        spendNote: '',
        form: {},
        errors: {},
        saving: false,
        ...window.amountFields({ decimals, locale, rules: { thm: { decimals: 3 }, months: { decimals: 0 }, dueDay: { decimals: 0, max: 31 } } }),
        moneyFormatter: new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }),

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
                prepay: @js(__('Prepayment')) + (this.form.loanName ? ' · ' + this.form.loanName : ''),
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
            this.moveMode = mode
            this.pocketTab = 'money'
            this.spendCategory = ''
            this.spendNote = ''
            this.open('pocket', data, data.id ? 'move' : null)
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
                if (result?.ok) { this.sheet = null; this.spendNote = ''; this.spendCategory = '' }
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
