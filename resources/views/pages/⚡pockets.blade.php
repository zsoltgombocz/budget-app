<?php

use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\SaveLoan;
use App\Enums\Currency;
use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pockets and loans')] class extends Component {
    public ?int $pocketId = null;

    public string $pocketName = '';

    public string $pocketTarget = '';

    public string $pocketStep = '';

    public bool $pocketIsReserve = false;

    public bool $pocketIsShared = false;

    public ?int $pocketLoanId = null;

    public string $moveAmount = '';

    public string $moveNote = '';

    public ?int $loanId = null;

    public string $loanName = '';

    public string $loanLender = '';

    public string $loanPrincipal = '';

    public string $loanInstallment = '';

    public string $loanInsurance = '';

    public string $loanThm = '';

    public ?int $loanMonths = null;

    public string $loanPrepayMode = 'reduce_installment';

    public string $prepayAmount = '';

    public ?int $prepayPocketId = null;

    public function editPocket(?int $pocketId = null): void
    {
        $this->resetValidation();
        $this->reset('pocketId', 'pocketName', 'pocketTarget', 'pocketStep', 'pocketIsReserve', 'pocketIsShared', 'pocketLoanId', 'moveAmount', 'moveNote');

        if ($pocketId !== null) {
            $pocket = $this->user()->pockets()->findOrFail($pocketId);
            $this->pocketId = $pocket->id;
            $this->pocketName = $pocket->name;
            $this->pocketTarget = Money::toInput($pocket->target_amount, $this->currency);
            $this->pocketStep = Money::toInput($pocket->prepay_step, $this->currency);
            $this->pocketIsReserve = $pocket->is_reserve;
            $this->pocketIsShared = $pocket->is_shared;
            $this->pocketLoanId = $pocket->loan_id;
        }

        $this->modal('pocket-editor')->show();
    }

    public function savePocket(): void
    {
        $user = $this->user();

        $this->validate([
            'pocketName' => ['required', 'string', 'max:80'],
            'pocketTarget' => ['nullable', $this->moneyRule()],
            'pocketStep' => ['nullable', $this->moneyRule()],
            'pocketLoanId' => ['nullable', 'integer', Rule::exists('loans', 'id')->where('user_id', $user->id)],
        ]);

        $data = [
            'name' => $this->pocketName,
            'target_amount' => $this->parse($this->pocketTarget),
            'prepay_step' => $this->parse($this->pocketStep),
            'is_reserve' => $this->pocketIsReserve,
            'is_shared' => $this->pocketIsShared,
            'loan_id' => $this->pocketLoanId,
        ];

        if ($this->pocketIsReserve) {
            $user->pockets()->where('id', '!=', $this->pocketId ?? 0)->update(['is_reserve' => false]);
        }

        if ($this->pocketId !== null) {
            $user->pockets()->findOrFail($this->pocketId)->update($data);
        } else {
            $user->pockets()->create([...$data, 'sort' => $user->pockets()->count() + 1]);
        }

        $this->modal('pocket-editor')->close();
        unset($this->pockets);
        Flux::toast(variant: 'success', text: __('Pocket saved.'));
    }

    public function movePocketMoney(int $direction, MovePocketMoney $movePocketMoney): void
    {
        $this->validate(['moveAmount' => ['required', $this->moneyRule()], 'moveNote' => ['nullable', 'string', 'max:255']]);

        $pocket = $this->user()->pockets()->findOrFail($this->pocketId);
        $movePocketMoney->handle($this->user(), $pocket, ($direction < 0 ? -1 : 1) * (int) $this->parse($this->moveAmount), $this->moveNote);

        $this->reset('moveAmount', 'moveNote');
        unset($this->pockets);
        Flux::toast(variant: 'success', text: __('Pocket balance updated.'));
    }

    public function deletePocket(): void
    {
        $this->user()->pockets()->findOrFail($this->pocketId)->delete();
        $this->modal('pocket-editor')->close();
        unset($this->pockets);
    }

    public function editLoan(?int $loanId = null): void
    {
        $this->resetValidation();
        $this->reset('loanId', 'loanName', 'loanLender', 'loanPrincipal', 'loanInstallment', 'loanInsurance', 'loanThm', 'loanMonths', 'loanPrepayMode');

        if ($loanId !== null) {
            $loan = $this->user()->loans()->findOrFail($loanId);
            $this->loanId = $loan->id;
            $this->loanName = $loan->name;
            $this->loanLender = $loan->lender ?? '';
            $this->loanPrincipal = Money::toInput($loan->principal_balance, $this->currency);
            $this->loanInstallment = Money::toInput($loan->installment, $this->currency);
            $this->loanInsurance = Money::toInput($loan->insurance, $this->currency);
            $this->loanThm = $loan->thm === null ? '' : (string) $loan->thm;
            $this->loanMonths = $loan->remaining_months;
            $this->loanPrepayMode = $loan->prepay_mode->value;
        }

        $this->modal('loan-editor')->show();
    }

    public function saveLoan(SaveLoan $saveLoan): void
    {
        $this->loanThm = str_replace(',', '.', trim($this->loanThm));

        $this->validate([
            'loanName' => ['required', 'string', 'max:80'],
            'loanLender' => ['nullable', 'string', 'max:80'],
            'loanPrincipal' => ['required', $this->moneyRule()],
            'loanInstallment' => ['required', $this->moneyRule()],
            'loanInsurance' => ['nullable', $this->moneyRule()],
            'loanThm' => ['nullable', 'numeric', 'between:0,100'],
            'loanMonths' => ['nullable', 'integer', 'between:1,600'],
            'loanPrepayMode' => ['required', Rule::enum(PrepayMode::class)],
        ]);

        $saveLoan->handle($this->user(), [
            'name' => $this->loanName,
            'lender' => $this->loanLender !== '' ? $this->loanLender : null,
            'principal_balance' => (int) $this->parse($this->loanPrincipal),
            'installment' => (int) $this->parse($this->loanInstallment),
            'insurance' => (int) $this->parse($this->loanInsurance),
            'thm' => $this->loanThm === '' ? null : (float) $this->loanThm,
            'remaining_months' => $this->loanMonths,
            'prepay_mode' => PrepayMode::from($this->loanPrepayMode),
        ], $this->loanId !== null ? $this->user()->loans()->findOrFail($this->loanId) : null);

        $this->modal('loan-editor')->close();
        unset($this->loans);
        Flux::toast(variant: 'success', text: __('Loan saved.'));
    }

    public function startPrepayment(int $loanId): void
    {
        $this->resetValidation();
        $this->loanId = $this->user()->loans()->findOrFail($loanId)->id;
        $pocket = $this->user()->pockets()->where('loan_id', $loanId)->first();
        $this->prepayPocketId = $pocket?->id;
        $this->prepayAmount = Money::toInput($pocket?->prepay_step !== null ? min($pocket->prepay_step, max(0, $pocket->balance)) : null, $this->currency);

        $this->modal('prepayment')->show();
    }

    public function prepay(RecordPrepayment $recordPrepayment): void
    {
        $user = $this->user();

        $this->validate([
            'prepayAmount' => ['required', $this->moneyRule()],
            'prepayPocketId' => ['nullable', 'integer', Rule::exists('pockets', 'id')->where('user_id', $user->id)],
        ]);

        $loan = $user->loans()->findOrFail($this->loanId);
        $pocket = $this->prepayPocketId !== null ? $user->pockets()->findOrFail($this->prepayPocketId) : null;

        $recordPrepayment->handle($user, $loan, (int) $this->parse($this->prepayAmount), $pocket);

        $this->modal('prepayment')->close();
        unset($this->loans, $this->pockets);
        Flux::toast(variant: 'success', text: __('Prepayment recorded. The new installment is :amount.', ['amount' => money($loan->refresh()->monthlyPayment())]));
    }

    /**
     * @return Collection<int, Pocket>
     */
    #[Computed]
    public function pockets(): Collection
    {
        return $this->user()->pockets()->with('loan')->orderByDesc('is_reserve')->orderBy('sort')->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    #[Computed]
    public function loans(): Collection
    {
        return $this->user()->loans()->with(['events' => fn ($query) => $query->latest('occurred_on')->latest('id')])->orderBy('name')->get();
    }

    #[Computed]
    public function currency(): Currency
    {
        return $this->user()->settings()->currency;
    }

    private function parse(string $value): ?int
    {
        return $value === '' ? null : Money::parse($value, $this->currency);
    }

    private function moneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && (! is_scalar($value) || Money::parse((string) $value, $this->currency) === null)) {
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

<div class="flex flex-col gap-6">
    <section class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <flux:heading>{{ __('Pockets') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="editPocket" data-test="add-pocket">{{ __('Add') }}</flux:button>
        </div>

        @if ($this->pockets->isEmpty())
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ __('Pockets collect money for a goal: a reserve, a prepayment, a holiday. Link a plan line to a pocket and it fills up at every month end.') }}</flux:callout.text>
            </flux:callout>
        @endif

        @foreach ($this->pockets as $pocket)
            @php
                $goal = $pocket->prepay_step ?? $pocket->target_amount;
                $percent = $goal ? min(100, (int) round(max(0, $pocket->balance) / $goal * 100)) : null;
            @endphp
            <button type="button" wire:key="pocket-{{ $pocket->id }}" wire:click="editPocket({{ $pocket->id }})"
                    class="flex flex-col gap-2 rounded-xl bg-white p-3 text-start shadow-xs dark:bg-zinc-800">
                <div class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate font-medium">{{ $pocket->name }}</span>
                    @if ($pocket->is_reserve)<flux:badge size="sm" color="teal">{{ __('Reserve') }}</flux:badge>@endif
                    @if ($pocket->is_shared)<flux:badge size="sm" color="violet">{{ __('Shared') }}</flux:badge>@endif
                    <x-money :amount="$pocket->balance" class="font-semibold" />
                </div>
                @if ($percent !== null)
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                        <div @class(['h-full rounded-full', 'bg-emerald-500' => $percent < 100, 'bg-amber-500' => $percent >= 100]) style="width: {{ $percent }}%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-zinc-500">
                        <span>{{ $pocket->prepay_step ? __('Prepayment step') : __('Target') }}: <x-money :amount="$goal" /></span>
                        @if ($pocket->hasReachedPrepayStep())
                            <span class="font-medium text-amber-600">{{ __('Ready to prepay') }}</span>
                        @else
                            <span>{{ $percent }}%</span>
                        @endif
                    </div>
                @endif
            </button>
        @endforeach
    </section>

    <section class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <flux:heading>{{ __('Loans') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="editLoan" data-test="add-loan">{{ __('Add') }}</flux:button>
        </div>

        @if ($this->loans->isEmpty())
            <flux:text class="text-sm">{{ __('No loans. Lucky you!') }}</flux:text>
        @endif

        @foreach ($this->loans as $loan)
            <div wire:key="loan-{{ $loan->id }}" class="flex flex-col gap-2 rounded-xl bg-white p-3 shadow-xs dark:bg-zinc-800">
                <button type="button" wire:click="editLoan({{ $loan->id }})" class="flex items-center gap-2 text-start">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $loan->name }}</span>
                        @if ($loan->lender)<span class="block text-xs text-zinc-500">{{ $loan->lender }}</span>@endif
                    </span>
                    <x-money :amount="$loan->principal_balance" class="font-semibold" />
                </button>
                <dl class="grid grid-cols-3 gap-2 text-xs">
                    <div><dt class="text-zinc-500">{{ __('Monthly') }}</dt><dd><x-money :amount="$loan->monthlyPayment()" class="text-sm" /></dd></div>
                    <div><dt class="text-zinc-500">{{ __('APR') }}</dt><dd class="text-sm">{{ $loan->thm !== null ? number_format($loan->thm, 2, ',', ' ').' %' : '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Months left') }}</dt><dd class="text-sm">{{ $loan->remaining_months ?? '—' }}</dd></div>
                </dl>
                @if ($loan->events->isNotEmpty())
                    <div class="text-xs text-zinc-500">
                        {{ __('Last prepayment') }}: <x-money :amount="$loan->events->first()->amount" /> · {{ $loan->events->first()->occurred_on->isoFormat('YYYY. MM. DD.') }}
                    </div>
                @endif
                <flux:button size="sm" icon="arrow-trending-down" wire:click="startPrepayment({{ $loan->id }})" data-test="prepay-loan-{{ $loan->id }}">{{ __('Record prepayment') }}</flux:button>
            </div>
        @endforeach
    </section>

    <flux:modal name="pocket-editor" variant="flyout" position="bottom" class="max-h-[90dvh]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $pocketId ? $pocketName : __('New pocket') }}</flux:heading>

            @if ($pocketId)
                <div class="flex flex-col gap-2 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-900">
                    <flux:input wire:model="moveAmount" :label="__('Amount')" inputmode="decimal" />
                    <flux:input wire:model="moveNote" :label="__('Note')" :placeholder="__('Optional')" />
                    <div class="flex gap-2">
                        <flux:button class="flex-1" icon="minus" wire:click="movePocketMoney(-1)">{{ __('Withdraw') }}</flux:button>
                        <flux:button class="flex-1" icon="plus" variant="primary" wire:click="movePocketMoney(1)" data-test="pocket-deposit">{{ __('Deposit') }}</flux:button>
                    </div>
                </div>
            @endif

            <form wire:submit="savePocket" class="flex flex-col gap-4">
                <flux:input wire:model="pocketName" :label="__('Name')" required />
                <flux:input wire:model="pocketTarget" :label="__('Target')" inputmode="decimal" :placeholder="__('Optional')" />
                <flux:input wire:model="pocketStep" :label="__('Prepayment step')" :description="__('Signal at closing when the balance reaches this amount.')" inputmode="decimal" :placeholder="__('Optional')" />
                <flux:select wire:model="pocketLoanId" :label="__('Prepays loan')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->loans as $loan)
                        <flux:select.option :value="$loan->id">{{ $loan->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:switch wire:model="pocketIsReserve" :label="__('This is the reserve')" />
                <flux:switch wire:model="pocketIsShared" :label="__('Shared pocket')" />
                <div class="flex gap-2">
                    @if ($pocketId)
                        <flux:button variant="danger" icon="trash" wire:click="deletePocket" wire:confirm="{{ __('Delete this pocket?') }}" :aria-label="__('Delete')" />
                    @endif
                    <flux:spacer />
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <flux:modal name="loan-editor" variant="flyout" position="bottom" class="max-h-[90dvh]">
        <form wire:submit="saveLoan" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $loanId ? __('Edit loan') : __('New loan') }}</flux:heading>
            <flux:input wire:model="loanName" :label="__('Name')" required />
            <flux:input wire:model="loanLender" :label="__('Lender')" :placeholder="__('Optional')" />
            <flux:input wire:model="loanPrincipal" :label="__('Outstanding principal')" inputmode="decimal" required />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="loanInstallment" :label="__('Installment')" inputmode="decimal" required />
                <flux:input wire:model="loanInsurance" :label="__('Insurance')" inputmode="decimal" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="loanThm" :label="__('APR (%)')" inputmode="decimal" />
                <flux:input type="number" wire:model="loanMonths" :label="__('Months left')" inputmode="numeric" />
            </div>
            <flux:radio.group wire:model="loanPrepayMode" :label="__('After a prepayment')" variant="segmented">
                @foreach (PrepayMode::cases() as $mode)
                    <flux:radio :value="$mode->value" :label="$mode->label()" />
                @endforeach
            </flux:radio.group>
            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="save-loan">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="prepayment" variant="flyout" position="bottom" class="max-h-[90dvh]">
        <form wire:submit="prepay" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Record prepayment') }}</flux:heading>
            <flux:input wire:model="prepayAmount" :label="__('Amount')" inputmode="decimal" required />
            <flux:select wire:model="prepayPocketId" :label="__('From pocket')">
                <flux:select.option value="">{{ __('Not from a pocket') }}</flux:select.option>
                @foreach ($this->pockets as $pocket)
                    <flux:select.option :value="$pocket->id">{{ $pocket->name }} ({{ money($pocket->balance) }})</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" data-test="confirm-prepayment">{{ __('Record prepayment') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
