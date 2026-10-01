<?php

use App\Actions\Budget\MovePocketMoney;
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
            'isReserve' => $pocket->is_reserve ?? false,
            'isShared' => $pocket->is_shared ?? false,
            'loanId' => $pocket?->loan_id,
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
    public function movePocketMoney(int $pocketId, int $direction, string $amount, ?string $note, MovePocketMoney $movePocketMoney): array
    {
        $value = $this->parse($amount);

        if ($value === null || $value <= 0) {
            return ['ok' => false, 'errors' => ['move' => __('Enter a valid amount.')]];
        }

        $pocket = $this->user()->pockets()->findOrFail($pocketId);
        $movePocketMoney->handle($this->user(), $pocket, ($direction < 0 ? -1 : 1) * $value, $note);

        unset($this->pockets);
        $this->dispatch('app-toast', title: $direction < 0 ? __('Withdrawn: :amount', ['amount' => money($value)]) : __('Deposited: :amount', ['amount' => money($value)]));

        return ['ok' => true, 'errors' => []];
    }

    public function deletePocket(int $pocketId): void
    {
        $this->user()->pockets()->findOrFail($pocketId)->delete();
        unset($this->pockets);
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
            'prepayMode' => $data['prepayMode'] ?? null,
        ], [
            'name' => ['required', 'string', 'max:80'],
            'lender' => ['nullable', 'string', 'max:80'],
            'principal' => ['required', $this->moneyRule()],
            'installment' => ['required', $this->moneyRule()],
            'insurance' => ['nullable', $this->moneyRule()],
            'thm' => ['nullable', 'numeric', 'between:0,100'],
            'months' => ['nullable', 'integer', 'between:1,600'],
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
        ], $id !== null ? $this->user()->loans()->findOrFail($id) : null);

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

<div x-data="pocketsPage({ decimals: {{ $decimals }}, locale: @js(str_replace('_', '-', app()->getLocale())) })">
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

    {{-- Shared sheet shell --}}
    <div x-show="sheet" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/55" x-on:click="sheet = null"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto flex max-h-[94dvh] max-w-lg flex-col rounded-t-[30px] bg-surface px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-2"
             x-show="sheet" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0">
            <div class="mx-auto h-[5px] w-9 shrink-0 rounded-full bg-ink/18"></div>
            <div class="mt-1 grid h-11 shrink-0 grid-cols-[72px_1fr_72px] items-center">
                <button type="button" class="text-left text-[15px] text-muted" x-on:click="sheet = null">{{ __('Cancel') }}</button>
                <div class="truncate text-center text-base font-semibold" x-text="title()"></div>
                <div class="text-right">
                    <button type="button" x-show="sheet === 'pocket' && form.id" x-on:click="removePocket()" class="text-danger" aria-label="{{ __('Delete') }}"><x-ui.icon name="delete" :size="22" /></button>
                </div>
            </div>

            <div class="no-scrollbar -mx-4 min-h-0 flex-1 overflow-y-auto px-4 pb-2">
                {{-- New: choose what to add --}}
                <template x-if="sheet === 'new'">
                    <div class="grid gap-2 pt-2">
                        <button type="button" x-on:click="openPocket(null)" class="flex items-center gap-3 rounded-btn bg-surface-2 p-4 text-left" data-test="new-pocket"><x-ui.icon-tile icon="savings" tone="accent" /><span><span class="block font-semibold">{{ __('New pocket') }}</span><span class="block text-xs text-muted">{{ __('Reserve, prepayment or any goal') }}</span></span></button>
                        <button type="button" x-on:click="openLoan(null)" class="flex items-center gap-3 rounded-btn bg-surface-2 p-4 text-left" data-test="new-loan"><x-ui.icon-tile icon="account_balance" /><span><span class="block font-semibold">{{ __('New loan') }}</span><span class="block text-xs text-muted">{{ __('Principal, installment and APR') }}</span></span></button>
                    </div>
                </template>

                {{-- Pocket --}}
                <template x-if="sheet === 'pocket'">
                    <div class="pt-1">
                        <template x-if="form.id">
                            <div class="rounded-btn bg-bg p-3">
                                <div class="num text-center text-sm text-muted">{{ __('Balance') }}: <span class="font-semibold text-ink" x-text="money(form.balance)"></span></div>
                                <button type="button" x-on:click="focus('move')" class="num mt-1 block w-full py-2 text-center text-[44px] font-semibold tracking-[-0.04em]" :class="! filled('move') && 'text-faint'" x-text="display('move')"></button>
                                <div class="mb-2 text-center text-xs text-danger" x-show="errors.move" x-text="errors.move"></div>
                                <div class="grid grid-cols-2 gap-2">
                                    <x-ui.button variant="secondary" size="md" icon="remove" x-on:click="move(-1)">{{ __('Withdraw') }}</x-ui.button>
                                    <x-ui.button size="md" icon="add" x-on:click="move(1)" data-test="pocket-deposit">{{ __('Deposit') }}</x-ui.button>
                                </div>
                            </div>
                        </template>

                        <label class="mt-3 block">
                            <span class="mb-1.5 block text-[13px] text-muted">{{ __('Name') }}</span>
                            <input type="text" x-model="form.name" maxlength="80" class="h-12 w-full rounded-[14px] bg-surface-2 px-4 text-[15px] outline-none focus:ring-2 focus:ring-accent" data-test="pocket-name">
                            <span class="mt-1 block text-xs text-danger" x-show="errors.name" x-text="errors.name"></span>
                        </label>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button type="button" x-on:click="focus('target')" class="rounded-[14px] border px-3 py-2 text-left" :class="active === 'target' ? 'border-accent bg-accent/8' : 'border-transparent bg-surface-2'">
                                <span class="block text-xs text-muted">{{ __('Target') }}</span>
                                <span class="num block truncate text-lg font-semibold" :class="! filled('target') && 'text-faint'" x-text="display('target', '–')"></span>
                            </button>
                            <button type="button" x-on:click="focus('step')" class="rounded-[14px] border px-3 py-2 text-left" :class="active === 'step' ? 'border-accent bg-accent/8' : 'border-transparent bg-surface-2'">
                                <span class="block text-xs text-muted">{{ __('Prepayment step') }}</span>
                                <span class="num block truncate text-lg font-semibold" :class="! filled('step') && 'text-faint'" x-text="display('step', '–')"></span>
                            </button>
                        </div>
                        <x-ui.numpad :decimal="$decimals > 0" class="mt-3" />
                        <label class="mt-3 block">
                            <span class="mb-1.5 block text-[13px] text-muted">{{ __('Prepays loan') }}</span>
                            <select x-model.number="form.loanId" class="h-12 w-full rounded-[14px] bg-surface-2 px-4 text-[15px] outline-none">
                                <option value="">{{ __('None') }}</option>
                                @foreach ($loans as $loan)<option value="{{ $loan->id }}">{{ $loan->name }}</option>@endforeach
                            </select>
                        </label>
                        <div class="mt-3 flex items-center justify-between"><span class="text-sm">{{ __('This is the reserve') }}</span>
                            <button type="button" role="switch" x-on:click="form.isReserve = ! form.isReserve" class="flex h-8 w-[52px] rounded-2xl p-[3px]" :class="form.isReserve ? 'justify-end bg-accent' : 'justify-start bg-zinc-600'"><span class="block size-[26px] rounded-full bg-white"></span></button></div>
                        <div class="mt-3 flex items-center justify-between"><span class="text-sm">{{ __('Shared pocket') }}</span>
                            <button type="button" role="switch" x-on:click="form.isShared = ! form.isShared" class="flex h-8 w-[52px] rounded-2xl p-[3px]" :class="form.isShared ? 'justify-end bg-accent' : 'justify-start bg-zinc-600'"><span class="block size-[26px] rounded-full bg-white"></span></button></div>
                    </div>
                </template>

                {{-- Loan --}}
                <template x-if="sheet === 'loan'">
                    <div class="pt-1">
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block"><span class="mb-1.5 block text-[13px] text-muted">{{ __('Name') }}</span><input type="text" x-model="form.name" maxlength="80" class="h-12 w-full rounded-[14px] bg-surface-2 px-4 outline-none focus:ring-2 focus:ring-accent" data-test="loan-name"></label>
                            <label class="block"><span class="mb-1.5 block text-[13px] text-muted">{{ __('Lender') }}</span><input type="text" x-model="form.lender" maxlength="80" class="h-12 w-full rounded-[14px] bg-surface-2 px-4 outline-none focus:ring-2 focus:ring-accent"></label>
                        </div>
                        <span class="mt-1 block text-xs text-danger" x-show="errors.name" x-text="errors.name"></span>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            @foreach (['principal' => __('Outstanding principal'), 'installment' => __('Installment'), 'insurance' => __('Insurance'), 'thm' => __('APR (%)'), 'months' => __('Months left')] as $field => $label)
                                <button type="button" x-on:click="focus(@js($field))" class="rounded-[14px] border px-3 py-2 text-left" :class="active === @js($field) ? 'border-accent bg-accent/8' : 'border-transparent bg-surface-2'" data-test="loan-{{ $field }}">
                                    <span class="block text-xs text-muted">{{ $label }}</span>
                                    <span class="num block truncate text-lg font-semibold" :class="! filled(@js($field)) && 'text-faint'" x-text="display(@js($field), '–')"></span>
                                    <span class="block text-xs text-danger" x-show="errors[@js($field)]" x-text="errors[@js($field)]"></span>
                                </button>
                            @endforeach
                        </div>
                        <x-ui.numpad :decimal="true" class="mt-3" />
                        <div class="mt-3 mb-1.5 text-[13px] text-muted">{{ __('After a prepayment') }}</div>
                        <div class="grid grid-cols-2 gap-[3px] rounded-xl bg-bg p-[3px]">
                            @foreach (PrepayMode::cases() as $mode)
                                <button type="button" x-on:click="form.prepayMode = @js($mode->value)" class="h-9 rounded-[9px] text-[13px] font-medium" :class="form.prepayMode === @js($mode->value) ? 'bg-surface-3 text-ink' : 'text-muted'">{{ $mode->label() }}</button>
                            @endforeach
                        </div>
                    </div>
                </template>

                {{-- Prepayment --}}
                <template x-if="sheet === 'prepay'">
                    <div class="pt-1">
                        <div class="num py-3 text-center text-[52px] font-semibold tracking-[-0.04em]" :class="! filled('prepay') && 'text-faint'" x-text="display('prepay')"></div>
                        <div class="mb-2 text-center text-xs text-danger" x-show="errors.prepay" x-text="errors.prepay"></div>
                        <label class="block">
                            <span class="mb-1.5 block text-[13px] text-muted">{{ __('From pocket') }}</span>
                            <select x-model.number="form.pocketId" class="h-12 w-full rounded-[14px] bg-surface-2 px-4 text-[15px] outline-none">
                                <option value="">{{ __('Not from a pocket') }}</option>
                                @foreach ($pockets as $pocket)<option value="{{ $pocket->id }}">{{ $pocket->name }} ({{ money($pocket->balance) }})</option>@endforeach
                            </select>
                        </label>
                        <x-ui.numpad :decimal="$decimals > 0" class="mt-3" />
                    </div>
                </template>
            </div>

            <template x-if="sheet && sheet !== 'new'">
                <x-ui.button x-on:click="submit()" ::disabled="saving" class="mt-2 w-full shrink-0" data-test="sheet-save">
                    <span x-text="sheet === 'prepay' ? @js(__('Record prepayment')) : @js(__('Save'))"></span>
                </x-ui.button>
            </template>
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('pocketsPage', ({ decimals, locale }) => ({
        sheet: null,
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
        },

        title() {
            return {
                new: @js(__('Add')),
                pocket: this.form.id ? this.form.name : @js(__('New pocket')),
                loan: this.form.id ? this.form.name : @js(__('New loan')),
                prepay: @js(__('Prepayment')) + (this.form.loanName ? ' · ' + this.form.loanName : ''),
            }[this.sheet] ?? ''
        },

        focus(name) { this.active = name },
        allowsDecimals(name) { return name === 'thm' ? 3 : (name === 'months' ? 0 : this.decimals) },
        press(key) {
            if (! this.active) return
            const places = this.allowsDecimals(this.active)
            let value = String(this.fields[this.active] ?? '')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (places > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (value && ! value.includes(',')) value += '000' }
            else {
                const fraction = value.split(',')[1]
                if (fraction !== undefined && fraction.length >= places) return
                if (value === '0') value = ''
                value += key
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
        async openPocket(id) { const data = await $wire.pocketData(id); this.open('pocket', data, id ? 'move' : 'target') },
        async openLoan(id) { const data = await $wire.loanData(id); this.open('loan', data, 'principal') },
        async openPrepay(id) { const data = await $wire.prepayData(id); this.open('prepay', data, 'prepay') },

        async move(direction) {
            const result = await $wire.movePocketMoney(this.form.id, direction, this.fields.move || '', null)
            this.errors = result.errors
            if (result.ok) this.sheet = null
        },
        async removePocket() {
            if (! confirm(@js(__('Delete this pocket?')))) return
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
