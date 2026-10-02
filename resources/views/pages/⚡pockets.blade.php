<?php

use App\Actions\Budget\DeletePocket;
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
        $deletePocket->handle($this->user()->pockets()->findOrFail($pocketId));
        unset($this->pockets);
        $this->dispatch('budget-updated');
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
    $others = $pockets->reject(fn ($p) => $p->is($featured) || $p->is($reserve))->values();
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
            <x-ui.icon-button icon="add" x-on:click="sheet = 'new'" :label="__('Add')" data-test="add" />
        </x-slot>
    </x-ui.page-header>

    @if ($pockets->isEmpty() && $loans->isEmpty())
        <x-ui.empty-state icon="savings" :title="__('No pockets yet')" class="mx-4 mt-5">
            {{ __('Pockets collect money for a goal: a reserve, a prepayment, a holiday. Link a plan line to a pocket and it fills up at every month end.') }}
        </x-ui.empty-state>
    @endif

    @if ($featured)
        @php
            $deposit = $this->monthlyDeposit($featured);
            $missing = max(0, $featured->prepay_step - $featured->balance);
        @endphp
        <button type="button" x-on:click="openPocket({{ $featured->id }})" class="mx-4 mb-3 mt-[18px] block w-[calc(100%-2rem)] rounded-card bg-surface p-[18px] text-left" data-test="pocket-{{ $featured->id }}">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-3">
                    <x-ui.icon-tile icon="trending_down" tone="accent" />
                    <div class="min-w-0">
                        <div class="text-[15px] font-semibold">{{ $featured->name }}</div>
                        @if ($deposit > 0)<div class="num mt-0.5 text-xs text-muted">+{{ money($deposit) }} {{ $perPeriod }}</div>@endif
                    </div>
                </div>
                <span class="num shrink-0 whitespace-nowrap rounded-lg bg-surface-2 px-2 py-[5px] font-mono text-[11px] text-ink-2">{{ __('step: :amount', ['amount' => money_number($featured->prepay_step)]) }}</span>
            </div>
            <div class="num mt-[18px] flex items-baseline gap-1.5"><span class="text-[30px] font-semibold tracking-[-0.02em]">{{ money_number($featured->balance) }}</span><span class="text-[15px] text-muted">/ {{ money($featured->prepay_step) }}</span></div>
            <x-ui.bar :value="$featured->balance / max(1, $featured->prepay_step)" :height="8" :tone="$featured->hasReachedPrepayStep() ? 'warn' : 'accent'" class="mt-3" />
            <div class="num mt-2 flex justify-between text-xs text-muted">
                @if ($missing > 0)
                    <span>{{ __(':amount to go', ['amount' => money($missing)]) }}</span>
                    @if ($deposit > 0)<span>{{ trans_choice('{1} about :count payday|[2,*] about :count paydays', (int) ceil($missing / $deposit), ['count' => (int) ceil($missing / $deposit)]) }}</span>@endif
                @else
                    <span class="font-medium text-warn">{{ __('Ready to prepay') }}</span>
                @endif
            </div>
        </button>
    @endif

    @if ($reserve)
        <button type="button" x-on:click="openPocket({{ $reserve->id }})" @class(['mx-4 mb-3 block w-[calc(100%-2rem)] rounded-card bg-surface p-[18px] text-left', 'mt-[18px]' => ! $featured]) data-test="pocket-{{ $reserve->id }}">
            <div class="flex items-center gap-3">
                <x-ui.icon-tile icon="shield" />
                <div>
                    <div class="text-[15px] font-semibold">{{ $reserve->name }}</div>
                    <div class="mt-0.5 text-xs text-muted">{{ __('filled from the month-end leftover') }}</div>
                </div>
            </div>
            <div class="num mt-[18px] flex items-baseline gap-1.5">
                <span class="text-[30px] font-semibold tracking-[-0.02em]">{{ money_number($reserve->balance) }}</span>
                <span class="text-[15px] text-muted">{{ $reserve->target_amount ? '/ '.money($reserve->target_amount) : user_currency()->symbol() }}</span>
            </div>
            @if ($reserve->target_amount)
                <x-ui.bar :value="$reserve->balance / max(1, $reserve->target_amount)" :height="8" class="mt-3" />
            @endif
        </button>
    @endif

    @if ($others->isNotEmpty())
        <div class="mx-4 mb-3 grid grid-cols-2 gap-3">
            @foreach ($others as $pocket)
                @php $goal = $pocket->target_amount ?? $pocket->prepay_step; @endphp
                <button type="button" x-on:click="openPocket({{ $pocket->id }})" wire:key="pocket-{{ $pocket->id }}" class="rounded-card bg-surface p-4 text-left" data-test="pocket-{{ $pocket->id }}">
                    <x-ui.icon-tile :icon="$pocket->is_shared ? 'group' : 'savings'" :size="36" />
                    <div class="mt-3 truncate text-sm font-semibold">{{ $pocket->name }}</div>
                    <div class="num mt-1.5 text-xl font-semibold">{{ money_number($pocket->balance) }}</div>
                    <div class="num text-xs text-muted">{{ $goal ? '/ '.money($goal) : user_currency()->symbol() }}</div>
                    @if ($goal)<x-ui.bar :value="$pocket->balance / max(1, $goal)" class="mt-2.5" />@endif
                </button>
            @endforeach
        </div>
    @endif

    @if ($loans->isNotEmpty())
        <div class="px-[30px] pb-2 pt-3.5 text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ __('Loans') }}</div>
        @foreach ($loans as $loan)
            @php
                $fund = $loan->pockets->first(fn ($p) => $p->prepay_step !== null);
                $next = $fund ? $this->nextInstallment($loan, $fund) : null;
                $detailed = $loop->first || $fund !== null;
            @endphp
            @if ($detailed)
                <div class="mx-4 mb-3 rounded-card bg-surface p-[18px]" wire:key="loan-{{ $loan->id }}" data-test="loan-{{ $loan->id }}">
                    <button type="button" x-on:click="openLoan({{ $loan->id }})" class="flex w-full items-center gap-3 text-left">
                        <x-ui.icon-tile icon="account_balance" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold">{{ $loan->name }}</span>
                            @if ($loan->lender)<span class="block text-xs text-muted">{{ $loan->lender }}</span>@endif
                        </span>
                        <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
                    </button>
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div><div class="text-xs text-muted">{{ __('Remaining principal') }}</div><div class="num mt-1 text-xl font-semibold">{{ money($loan->principal_balance) }}</div></div>
                        <div><div class="text-xs text-muted">{{ __('Monthly installment') }}</div><div class="num mt-1 text-xl font-semibold">{{ money($loan->monthlyPayment()) }}</div></div>
                    </div>
                    @if ($next !== null && $next < $loan->monthlyPayment())
                        <div class="mt-4 rounded-btn border border-accent/22 bg-accent/10 px-4 py-3.5">
                            <div class="text-[13px] leading-snug text-ink-2">{{ __('Expected installment after the next prepayment:') }}</div>
                            <div class="num mt-2 flex items-center gap-2.5">
                                <span class="text-[15px] text-muted line-through">{{ money_number($loan->monthlyPayment()) }}</span>
                                <x-ui.icon name="arrow_forward" :size="18" class="text-accent" />
                                <span class="text-2xl font-semibold text-accent">~{{ money($next) }}</span>
                            </div>
                            <div class="mt-1.5 text-xs text-muted">{{ __('when :pocket reaches :step', ['pocket' => $fund->name, 'step' => money($fund->prepay_step)]) }}</div>
                        </div>
                    @endif
                    <x-ui.button variant="secondary" size="md" icon="trending_down" x-on:click="openPrepay({{ $loan->id }})" class="mt-3 w-full" data-test="prepay-loan-{{ $loan->id }}">{{ __('Record prepayment') }}</x-ui.button>
                </div>
            @else
                <button type="button" x-on:click="openLoan({{ $loan->id }})" wire:key="loan-{{ $loan->id }}" class="mx-4 mb-3 flex w-[calc(100%-2rem)] items-center justify-between rounded-card bg-surface px-[18px] py-4 text-left" data-test="loan-{{ $loan->id }}">
                    <span class="flex min-w-0 items-center gap-3"><x-ui.icon-tile icon="work" /><span class="truncate text-[15px] font-semibold">{{ $loan->name }}</span></span>
                    <span class="num shrink-0 text-sm text-muted">{{ __('monthly :amount', ['amount' => money($loan->monthlyPayment())]) }}</span>
                </button>
            @endif
        @endforeach
    @endif

    {{-- Add: choose a pocket or a loan --}}
    <x-ui.form-sheet show="sheet === 'new'" close="sheet = null" :label="__('Add')" :full="false">
        <div class="grid gap-2 pb-[env(safe-area-inset-bottom)]">
            <button type="button" x-on:click="openPocket(null)" class="flex items-center gap-3 rounded-btn bg-surface-2 p-4 text-left" data-test="new-pocket"><x-ui.icon-tile icon="savings" tone="accent" /><span><span class="block font-semibold">{{ __('New pocket') }}</span><span class="block text-xs text-muted">{{ __('Reserve, prepayment or any goal') }}</span></span></button>
            <button type="button" x-on:click="openLoan(null)" class="flex items-center gap-3 rounded-btn bg-surface-2 p-4 text-left" data-test="new-loan"><x-ui.icon-tile icon="account_balance" /><span><span class="block font-semibold">{{ __('New loan') }}</span><span class="block text-xs text-muted">{{ __('Principal, installment and APR') }}</span></span></button>
        </div>
    </x-ui.form-sheet>

    {{-- Pocket: money movements and settings --}}
    <x-ui.form-sheet show="sheet === 'pocket'" close="sheet = null" title="title()" data-test="pocket-sheet">
        <x-slot:action>
            <button type="button" x-show="form.id" x-on:click="removePocket()" class="text-danger" aria-label="{{ __('Delete') }}"><x-ui.icon name="delete" :size="22" /></button>
        </x-slot:action>

        <template x-if="form.id">
            <div>
                <div class="rounded-2xl bg-bg px-4 py-4 text-center">
                    <div class="text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ __('Balance') }}</div>
                    <div class="num mt-1 text-[34px] font-semibold tracking-[-0.03em]" x-text="money(form.balance)"></div>
                    <div class="num mt-0.5 text-xs text-muted" x-show="form.target" x-text="@js(__('Target')) + ': ' + money(form.target)"></div>
                </div>
                <x-ui.segmented model="pocketTab" :options="['money' => __('Move money'), 'settings' => __('Settings')]" class="mt-3" />
            </div>
        </template>

        <div x-show="form.id && pocketTab === 'money'" class="pt-3">
            <x-ui.form-group :title="__('What do you want to do?')">
                <div class="p-3">
                    <x-ui.segmented model="moveMode" :options="['deposit' => __('Deposit'), 'budget' => __('To the budget'), 'spend' => __('Pay a spending')]" />
                    <p class="mt-2.5 px-0.5 text-xs leading-snug text-muted" x-show="moveMode === 'deposit'">{{ __('Money into the pocket. The budget does not change.') }}</p>
                    <p class="mt-2.5 px-0.5 text-xs leading-snug text-muted" x-show="moveMode === 'budget'">{{ __('Added to this period’s budget; the daily budget grows.') }}</p>
                    <p class="mt-2.5 px-0.5 text-xs leading-snug text-muted" x-show="moveMode === 'spend'">{{ __('A spending paid by the pocket; it does not use up the budget.') }}</p>
                </div>
            </x-ui.form-group>
            <x-ui.form-group>
                <x-ui.amount-row name="move" :label="__('Amount')" fallback="0" error="move" data-test="field-move" />
                <template x-if="moveMode === 'spend'">
                    <div class="divide-y divide-line">
                        <x-ui.select-row :label="__('Category')" x-model.number="spendCategory" data-test="spend-category">
                            <option value="">{{ __('Choose a category') }}</option>
                            @foreach ($this->spendCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                        </x-ui.select-row>
                        <x-ui.text-row :label="__('Note')" x-model="spendNote" maxlength="255" :placeholder="__('Optional')" />
                    </div>
                </template>
            </x-ui.form-group>
            <p class="px-1.5 pt-2 text-xs text-danger" x-show="errors.category" x-text="errors.category"></p>

            <template x-if="form.movements?.length">
                <x-ui.form-group :title="__('Recent movements')">
                    <template x-for="movement in form.movements">
                        <div class="num flex justify-between gap-2 px-4 py-2.5 text-[13px]"><span class="truncate text-muted" x-text="movement.date + ' · ' + movement.label"></span><span class="shrink-0 font-medium" x-text="movement.amount"></span></div>
                    </template>
                </x-ui.form-group>
            </template>
        </div>

        <div x-show="! form.id || pocketTab === 'settings'" :class="form.id && 'pt-3'">
            <x-ui.form-group :title="__('Basics')">
                <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" :placeholder="__('e.g. Holiday')" error="name" data-test="pocket-name" />
                <x-ui.amount-row name="target" :label="__('Target')" />
            </x-ui.form-group>
            <x-ui.form-group :title="__('Loan prepayment')" :hint="__('When the pocket reaches the step, the loan card shows how much the installment drops.')">
                <x-ui.select-row :label="__('Prepays loan')" x-model.number="form.loanId">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($loans as $loan)<option value="{{ $loan->id }}">{{ $loan->name }}</option>@endforeach
                </x-ui.select-row>
                <x-ui.amount-row name="step" :label="__('Prepayment step')" x-show="form.loanId" />
            </x-ui.form-group>
            <x-ui.form-group :title="__('Role')">
                <x-ui.switch-row model="form.isReserve" :label="__('This is the reserve')" :hint="__('At closing the leftover goes here first, a deficit comes from here.')" />
                <x-ui.switch-row model="form.isShared" :label="__('Shared pocket')" :hint="__('Only a marker.')" />
            </x-ui.form-group>
        </div>

        <x-slot:footer>
            <x-ui.button x-show="form.id && pocketTab === 'money'" x-on:click="move()" ::disabled="saving" class="w-full" data-test="pocket-move">
                <span x-text="{ deposit: @js(__('Deposit')), budget: @js(__('Withdraw to the budget')), spend: @js(__('Pay from the pocket')) }[moveMode]"></span>
            </x-ui.button>
            <x-ui.button x-show="! form.id || pocketTab === 'settings'" x-on:click="submit()" ::disabled="saving" class="w-full" data-test="sheet-save">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$decimals > 0" /></x-slot:pad>
    </x-ui.form-sheet>

    {{-- Loan --}}
    <x-ui.form-sheet show="sheet === 'loan'" close="sheet = null" title="title()" data-test="loan-sheet">
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
    </x-ui.form-sheet>

    {{-- Prepayment --}}
    <x-ui.form-sheet show="sheet === 'prepay'" close="sheet = null" title="title()" :full="false" data-test="prepay-sheet">
        <x-slot:intro>{{ __('If you paid it from a pocket, its balance goes down too.') }}</x-slot:intro>
        <div class="num py-2 text-center text-[48px] font-semibold tracking-[-0.04em]" :class="! filled('prepay') && 'text-faint'" x-text="display('prepay')"></div>
        <div class="mb-2 text-center text-xs text-danger" x-show="errors.prepay" x-text="errors.prepay"></div>
        <x-ui.form-group>
            <x-ui.select-row :label="__('From pocket')" x-model.number="form.pocketId">
                <option value="">{{ __('Not from a pocket') }}</option>
                @foreach ($pockets as $pocket)<option value="{{ $pocket->id }}">{{ $pocket->name }} ({{ money($pocket->balance) }})</option>@endforeach
            </x-ui.select-row>
        </x-ui.form-group>
        <x-ui.numpad :decimal="$decimals > 0" class="mt-3" />
        <x-slot:footer>
            <x-ui.button x-on:click="submit()" ::disabled="saving" class="w-full" data-test="sheet-save">{{ __('Record prepayment') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.form-sheet>
</div>

@script
<script>
    Alpine.data('pocketsPage', ({ decimals, locale, cover, openLoan, returnTo }) => ({
        sheet: null,
        moveMode: 'deposit',
        pocketTab: 'money',
        activeLabel: '',
        spendCategory: '',
        spendNote: '',
        form: {},
        fields: {},
        active: null,
        errors: {},
        saving: false,
        decimals,
        formatter: new Intl.NumberFormat(locale, { maximumFractionDigits: 2, useGrouping: 'always' }),
        moneyFormatter: new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }),

        init() {
            this.$watch('sheet', open => document.documentElement.classList.toggle('overflow-hidden', !! open))
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
                this.openPocket(cover.pocketId).then(() => { this.moveMode = 'budget'; this.fields.move = String(cover.amount) })
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

        focus(name, label = '') { this.active = name; this.activeLabel = label },
        allowsDecimals(name) { return name === 'thm' ? 3 : (name === 'months' || name === 'dueDay' ? 0 : this.decimals) },
        press(key) {
            if (! this.active) return
            const places = this.allowsDecimals(this.active)
            let value = String(this.fields[this.active] ?? '')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (places > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (value && ! value.includes(',') && this.active !== 'dueDay') value += '000' }
            else {
                const fraction = value.split(',')[1]
                if (fraction !== undefined && fraction.length >= places) return
                if (value === '0') value = ''
                value += key
                if (this.active === 'dueDay' && parseInt(value, 10) > 31) value = key
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
        money(minor) { return this.moneyFormatter.format(minor / Math.pow(10, this.decimals)) },

        open(sheet, data, active) {
            this.form = data
            this.fields = { ...data.amounts }
            this.active = active
            this.errors = {}
            this.sheet = sheet
        },
        async openPocket(id) { const data = await $wire.pocketData(id); this.moveMode = 'deposit'; this.pocketTab = 'money'; this.open('pocket', data, null) },
        async openLoan(id) { const data = await $wire.loanData(id); this.open('loan', data, null) },
        async openPrepay(id) { const data = await $wire.prepayData(id); this.open('prepay', data, 'prepay') },

        async move() {
            const amount = this.fields.move || ''
            const result = this.moveMode === 'spend'
                ? await $wire.payFromPocket(this.form.id, amount, this.spendCategory || null, this.spendNote || null)
                : await $wire.movePocketMoney(this.form.id, this.moveMode === 'deposit' ? 1 : -1, amount, null, true)
            this.errors = result.errors
            if (result.ok) { this.sheet = null; this.spendNote = ''; this.spendCategory = '' }
        },
        async removePocket() {
            if (! confirm(@js(__('Delete this pocket? Its monthly saving is removed from the plan too.')))) return
            await $wire.deletePocket(this.form.id)
            this.sheet = null
        },
        async submit() {
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
