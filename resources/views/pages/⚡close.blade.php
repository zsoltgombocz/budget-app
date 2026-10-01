<?php

use App\Actions\Budget\RecordPrepayment;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\User;
use App\Services\Data\ClosePreview;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Close the period')] class extends Component {
    #[Locked]
    public int $periodId;

    public int $step = 1;

    public string $incomeActual = '';

    /** @var list<int> pockets whose prepayment was recorded after closing */
    public array $prepaid = [];

    public function mount(int $period): void
    {
        $model = $this->user()->periods()->findOrFail($period);
        $this->periodId = $model->id;
        $this->incomeActual = Money::toInput($model->income(), $this->user()->settings()->currency);
        $this->step = $model->isOpen() ? 1 : 4;
    }

    public function next(): void
    {
        $this->validateIncome();
        $this->step = min(3, $this->step + 1);
        unset($this->preview);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function close(PeriodCloser $closer): void
    {
        $this->validateIncome();
        $closer->close($this->user(), $this->period, $this->income());

        $this->step = 4;
        unset($this->period, $this->preview);

        Flux::toast(variant: 'success', text: __('Period closed. A new one has started.'));
    }

    public function prepay(int $pocketId, RecordPrepayment $recordPrepayment): void
    {
        $user = $this->user();
        $pocket = $user->pockets()->findOrFail($pocketId);
        $loan = $pocket->loan_id !== null ? $user->loans()->find($pocket->loan_id) : null;

        abort_if($loan === null || $pocket->prepay_step === null, 404);

        $recordPrepayment->handle($user, $loan, min($pocket->prepay_step, $pocket->balance), $pocket);
        $this->prepaid[] = $pocket->id;

        Flux::toast(variant: 'success', text: __('Prepayment recorded. The new installment is :amount.', ['amount' => money($loan->refresh()->monthlyPayment())]));
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function preview(): ClosePreview
    {
        return app(PeriodCloser::class)->preview($this->user(), $this->period, $this->income());
    }

    #[Computed]
    public function closeRecord(): ?PeriodClose
    {
        return $this->period->close()->first();
    }

    #[Computed]
    public function isEarly(): bool
    {
        return app(PeriodService::class)->today($this->user()->settings())->lessThan($this->period->ends_on);
    }

    private function income(): ?int
    {
        return Money::parse($this->incomeActual, $this->user()->settings()->currency);
    }

    private function validateIncome(): void
    {
        if ($this->income() === null) {
            throw ValidationException::withMessages(['incomeActual' => __('Enter a valid amount.')]);
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">
            {{ $this->period->starts_on->isoFormat('YYYY. MM. DD.') }} – {{ $this->period->ends_on->isoFormat('MM. DD.') }}
        </flux:heading>
        @if ($step <= 3)
            <flux:badge size="sm">{{ __('Step :step of :total', ['step' => $step, 'total' => 3]) }}</flux:badge>
        @endif
    </div>

    @if ($step === 1)
        @if ($this->isEarly)
            <flux:callout icon="exclamation-triangle" variant="warning">
                <flux:callout.text>{{ __('The period has not ended yet. You can still close it early.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:input wire:model.blur="incomeActual" :label="__('Actual income')" inputmode="decimal" data-test="income-actual" />

        <div class="flex flex-col gap-1">
            <flux:heading>{{ __('Actual vs plan') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl bg-white shadow-xs dark:divide-zinc-700 dark:bg-zinc-800">
                @foreach ($this->preview->categories as $row)
                    <li wire:key="row-{{ $row['category_id'] }}" @class(['flex items-center gap-2 px-3 py-2 text-sm', 'bg-red-50 dark:bg-red-950/30' => $row['deviates'] && $row['diff'] > 0])>
                        <span class="min-w-0 flex-1 truncate">{{ $row['name'] }}</span>
                        <span class="text-xs text-zinc-500"><x-money :amount="$row['planned']" /></span>
                        <x-money :amount="$row['actual']" class="w-24 text-end font-medium" />
                        <x-money :amount="$row['diff']" signed @class([
                            'w-20 text-end text-xs',
                            'text-red-600 dark:text-red-400' => $row['diff'] > 0,
                            'text-emerald-600 dark:text-emerald-400' => $row['diff'] < 0,
                            'text-zinc-400' => $row['diff'] === 0,
                        ]) />
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="flex justify-between rounded-xl bg-white p-3 text-sm shadow-xs dark:bg-zinc-800">
            <span>{{ __('Planned') }}: <x-money :amount="$this->preview->plannedTotal" /></span>
            <span class="font-medium">{{ __('Actual') }}: <x-money :amount="$this->preview->actualTotal" /></span>
        </div>
    @elseif ($step === 2)
        @php($allocation = $this->preview->allocation)
        <section class="rounded-2xl bg-zinc-900 p-4 text-white dark:bg-white dark:text-zinc-900">
            <div class="text-sm opacity-80">{{ __('Leftover') }}</div>
            <x-money :amount="$this->preview->leftover()" @class(['block text-3xl font-semibold', 'text-red-400 dark:text-red-600' => $this->preview->leftover() < 0]) />
        </section>

        <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl bg-white text-sm shadow-xs dark:divide-zinc-700 dark:bg-zinc-800" data-test="allocation">
            @if ($allocation->fromReserve > 0)
                <li class="flex justify-between px-3 py-2"><span>{{ __('Taken from the reserve') }}</span><x-money :amount="-$allocation->fromReserve" /></li>
            @endif
            @if ($allocation->uncovered > 0)
                <li class="flex justify-between px-3 py-2 text-red-600 dark:text-red-400"><span>{{ __('Not covered by the reserve') }}</span><x-money :amount="-$allocation->uncovered" /></li>
            @endif
            @if ($this->preview->leftover() >= 0)
                <li class="flex justify-between px-3 py-2"><span>{{ __('To the reserve') }}</span><x-money :amount="$allocation->toReserve" /></li>
                <li class="flex justify-between px-3 py-2">
                    <span>{{ $this->preview->surplusTarget['name'] ? __('To :target', ['target' => $this->preview->surplusTarget['name']]) : __('Stays on the account') }}</span>
                    <x-money :amount="$allocation->toSurplus" />
                </li>
            @endif
        </ul>

        @if ($this->preview->pocketDeposits !== [])
            <div class="flex flex-col gap-1">
                <flux:heading>{{ __('Monthly pocket savings') }}</flux:heading>
                <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl bg-white text-sm shadow-xs dark:divide-zinc-700 dark:bg-zinc-800">
                    @foreach ($this->preview->pocketDeposits as $deposit)
                        <li wire:key="deposit-{{ $deposit['pocket_id'] }}" class="flex justify-between px-3 py-2"><span>{{ $deposit['name'] }}</span><x-money :amount="$deposit['amount']" signed /></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($this->preview->prepayReady as $ready)
            <flux:callout icon="sparkles" variant="success" wire:key="ready-{{ $ready['pocket_id'] }}">
                <flux:callout.text>{{ __(':pocket will reach :step, you can prepay after closing.', ['pocket' => $ready['name'], 'step' => money($ready['step'])]) }}</flux:callout.text>
            </flux:callout>
        @endforeach
    @elseif ($step === 3)
        <flux:callout icon="lock-closed">
            <flux:callout.heading>{{ __('Ready to close') }}</flux:callout.heading>
            <flux:callout.text>{{ __('The plan of this period is frozen, pocket balances are updated and the next period opens with a copy of the plan. Spending can no longer be recorded into this period.') }}</flux:callout.text>
        </flux:callout>

        <dl class="grid grid-cols-2 gap-2 text-sm">
            <div class="rounded-xl bg-white p-3 shadow-xs dark:bg-zinc-800"><dt class="text-zinc-500">{{ __('Actual income') }}</dt><dd><x-money :amount="$this->preview->incomeActual" class="font-semibold" /></dd></div>
            <div class="rounded-xl bg-white p-3 shadow-xs dark:bg-zinc-800"><dt class="text-zinc-500">{{ __('Leftover') }}</dt><dd><x-money :amount="$this->preview->leftover()" class="font-semibold" /></dd></div>
        </dl>
    @else
        @php($record = $this->closeRecord)
        <flux:callout icon="check-circle" variant="success">
            <flux:callout.heading>{{ __('Period closed') }}</flux:callout.heading>
            @if ($record)
                <flux:callout.text>
                    {{ __('Leftover') }}: <x-money :amount="$record->leftover" /> ·
                    {{ __('To the reserve') }}: <x-money :amount="$record->to_reserve" /> ·
                    {{ __('Rest') }}: <x-money :amount="$record->to_invest" />
                </flux:callout.text>
            @endif
        </flux:callout>

        @foreach (($record?->breakdown['prepay_ready'] ?? []) as $ready)
            @if ($ready['loan_id'] && ! in_array($ready['pocket_id'], $prepaid, true))
                <flux:card wire:key="prepay-{{ $ready['pocket_id'] }}" class="flex flex-col gap-3">
                    <flux:heading>{{ __('Prepayment ready') }}</flux:heading>
                    <flux:text>{{ __(':pocket reached :step. Record the prepayment to lower the next installment.', ['pocket' => $ready['name'], 'step' => money($ready['step'])]) }}</flux:text>
                    <flux:button variant="primary" wire:click="prepay({{ $ready['pocket_id'] }})" data-test="prepay-{{ $ready['pocket_id'] }}">{{ __('Record prepayment') }}</flux:button>
                </flux:card>
            @endif
        @endforeach

        <flux:button :href="route('dashboard')" wire:navigate variant="primary">{{ __('To the new period') }}</flux:button>
    @endif

    @if ($step <= 3)
        <div class="flex gap-2">
            @if ($step > 1)
                <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Back') }}</flux:button>
            @endif
            <flux:spacer />
            @if ($step < 3)
                <flux:button wire:click="next" variant="primary" data-test="close-next">{{ __('Next') }}</flux:button>
            @else
                <flux:button wire:click="close" variant="primary" icon="lock-closed" data-test="close-confirm">{{ __('Close the period') }}</flux:button>
            @endif
        </div>
    @endif
</div>
