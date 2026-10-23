<?php

use App\Actions\Budget\RecordPrepayment;
use App\Actions\Month\PlanLoan;
use App\Enums\LineType;
use App\Enums\LoanEventType;
use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\User;
use App\Services\Data\LoanSummary;
use App\Services\LoanOverview;
use App\Services\PeriodService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Mikor leszek hitelmentes?" (S23, Plusz): a loan installment line's loan, the planner with a live
 * result, the prepayment being saved up and "Előtörlesztettem". The app never moves money: the
 * prepayment happens in the bank, here it is only noted. The numbers are estimates (LoanPlanner).
 */
new #[Title('When will I be loan-free?')] class extends Component {
    #[Locked]
    public int $lineId;

    /** The planner's slider: the monthly prepayment saving, in the smallest unit. */
    public int $monthly = 0;

    /** Prepaid each time this much is set aside. */
    public int $step = 0;

    public string $mode = 'reduce_term';

    public function mount(BudgetLine $line): void
    {
        $line->loadMissing('category');
        abort_unless($line->category?->type === LineType::Loan, 404);
        $this->lineId = $line->id;
        $this->resetPlanner();
    }

    /**
     * The loan's details ("Új hitel" / "Hitel adatai").
     *
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function saveDetails(array $form): array
    {
        $currency = $this->user()->settings()->currency;
        $thm = is_string($form['thm'] ?? null) ? str_replace([',', ' ', '%'], ['.', '', ''], $form['thm']) : '';

        try {
            resolve(PlanLoan::class)->details($this->user(), $this->line(), [
                'principal' => is_string($form['principal'] ?? null) ? (Money::parse($form['principal'], $currency) ?? 0) : 0,
                'thm' => is_numeric($thm) ? (float) $thm : -1.0,
                'remaining_months' => is_numeric($form['months'] ?? null) ? (int) $form['months'] : 0,
                'prepay_mode' => PrepayMode::tryFrom(is_string($form['mode'] ?? null) ? $form['mode'] : '') ?? PrepayMode::ReduceTerm,
            ]);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => array_map(fn (array $messages): string => $messages[0] ?? '', $exception->errors())];
        }

        unset($this->loan, $this->summary);
        $this->resetPlanner();
        $this->dispatch('app-toast', title: __('Loan saved'), subtitle: __('Runs out: :month', ['month' => \App\Support\Dates::yearMonth($this->summary->plan->endWithout)]));

        return ['ok' => true, 'errors' => []];
    }

    /**
     * "Így szeretném": the monthly saving becomes a savings line (from this month on).
     */
    public function savePlan(): void
    {
        $loan = $this->loan;
        abort_unless($loan instanceof Loan && $this->user()->hasPlusz(), 403);

        try {
            resolve(PlanLoan::class)->prepay($this->user(), $loan, max(0, $this->monthly), max(0, $this->step), PrepayMode::tryFrom($this->mode) ?? PrepayMode::ReduceTerm);
        } catch (ValidationException $exception) {
            $this->dispatch('app-toast', title: (string) collect($exception->errors())->flatten()->first(), icon: 'error');

            return;
        }

        unset($this->summary);
        $this->dispatch('app-toast',
            title: $this->monthly > 0 ? __('Saving :amount a month for prepayment', ['amount' => money($this->monthly)]) : __('Prepayment saving stopped'),
            subtitle: $this->monthly > 0 ? __('It is a fixed line from this month on: on its day tap “Félretettem”.') : __('What is set aside so far stays noted.'),
        );
    }

    /**
     * The prepayment step, from its numpad.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function setStep(string $value): array
    {
        $amount = Money::parse($value, $this->user()->settings()->currency);

        if ($amount === null || $amount < 1) {
            return ['ok' => false, 'error' => __('Enter an amount.')];
        }

        $this->step = $amount;
        unset($this->preview);

        return ['ok' => true, 'error' => null];
    }

    /**
     * "Előtörlesztettem": noted after it happened in the bank. Taken from the saving when it covers it.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function recordPrepayment(string $value): array
    {
        $loan = $this->loan;
        abort_unless($loan instanceof Loan, 404);
        $amount = Money::parse($value, $this->user()->settings()->currency);

        if ($amount === null || $amount < 1) {
            return ['ok' => false, 'error' => __('Enter an amount.')];
        }

        if ($amount > $loan->principal_balance) {
            return ['ok' => false, 'error' => __('That is more than what is left of the loan (:amount).', ['amount' => money($loan->principal_balance)])];
        }

        $pocket = $this->summary->pocket;
        $event = resolve(RecordPrepayment::class)->handle($this->user(), $loan, $amount, $pocket !== null && $pocket->balance >= $amount ? $pocket : null);

        unset($this->loan, $this->summary, $this->prepayments);
        $this->dispatch('app-toast',
            title: __('Great! It now runs out: :month', ['month' => \App\Support\Dates::yearMonth($this->summary->plan->endWith)]),
            subtitle: __(':amount prepayment noted', ['amount' => money($amount)]),
            undo: 'loan-undo-prepayment',
            params: ['eventId' => $event->id],
        );

        return ['ok' => true, 'error' => null];
    }

    #[On('loan-undo-prepayment')]
    public function undoPrepayment(int $eventId): void
    {
        resolve(RecordPrepayment::class)->undo($this->user(), $this->user()->loanEvents()->findOrFail($eventId));
        unset($this->loan, $this->summary, $this->prepayments);
    }

    public function updatedMonthly(): void
    {
        unset($this->preview);
    }

    public function updatedMode(): void
    {
        unset($this->preview);
    }

    #[Computed]
    public function loan(): ?Loan
    {
        $loanId = $this->line()->loan_id;

        return $loanId !== null ? $this->user()->loans()->find($loanId) : null;
    }

    #[Computed]
    public function summary(): ?LoanSummary
    {
        return $this->loan instanceof Loan ? resolve(LoanOverview::class)->summary($this->user(), $this->loan, $this->today()) : null;
    }

    /** The planner's result for the slider's amount, the step and the mode picked. */
    #[Computed]
    public function preview(): ?LoanSummary
    {
        if (! $this->loan instanceof Loan) {
            return null;
        }

        return resolve(LoanOverview::class)->summary($this->user(), $this->loan, $this->today(), max(0, $this->monthly), max(1, $this->step), PrepayMode::tryFrom($this->mode) ?? PrepayMode::ReduceTerm);
    }

    /**
     * @return \Illuminate\Support\Collection<int, LoanEvent>
     */
    #[Computed]
    public function prepayments(): \Illuminate\Support\Collection
    {
        return $this->loan instanceof Loan
            ? $this->loan->events()->where('type', LoanEventType::Prepayment)->oldest('occurred_on')->oldest('id')->get()->toBase()
            : collect();
    }

    /**
     * The slider's range in the smallest unit: 0–100 000 Ft in 5 000s (0–1 000 in 50s in a currency with cents).
     *
     * @return array{max: int, step: int, default: int, prepayStep: int}
     */
    public function slider(): array
    {
        $unit = 10 ** $this->user()->settings()->currency->decimals();

        return $unit === 1
            ? ['max' => 100_000, 'step' => 5_000, 'default' => 30_000, 'prepayStep' => 500_000]
            : ['max' => 1_000 * $unit, 'step' => 50 * $unit, 'default' => 300 * $unit, 'prepayStep' => 5_000 * $unit];
    }

    private function resetPlanner(): void
    {
        $summary = $this->summary;
        $slider = $this->slider();
        $this->monthly = $summary !== null && $summary->monthlySaving > 0 ? $summary->monthlySaving : $slider['default'];
        $this->step = $summary !== null && $summary->step() > 0 ? $summary->step() : $slider['prepayStep'];
        $this->mode = ($summary->loan->prepay_mode ?? PrepayMode::ReduceTerm)->value;
        unset($this->preview);
    }

    private function line(): BudgetLine
    {
        return $this->user()->budgetLines()->with('category')->findOrFail($this->lineId);
    }

    private function today(): CarbonImmutable
    {
        return resolve(PeriodService::class)->today($this->user()->settings());
    }

    private function user(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}; ?>

@php
    use App\Support\Dates;
    $currency = auth()->user()->settings()->currency;
    $line = $this->line();
    $loan = $this->loan;
    $summary = $this->summary;
    $preview = $this->preview;
    $slider = $this->slider();
    $sooner = function (int $months): string {
        $years = intdiv($months, 12);
        $rest = $months % 12;

        return match (true) {
            $years > 0 && $rest > 0 => __(':years years :months months sooner', ['years' => $years, 'months' => $rest]),
            $years > 0 => trans_choice('{1} :count year sooner|[2,*] :count years sooner', $years),
            default => trans_choice('{1} :count month sooner|[2,*] :count months sooner', $rest),
        };
    };
    $details = [
        'principal' => $loan ? Money::toInput($loan->principal_balance, $currency) : '',
        'thm' => $loan && $loan->thm !== null ? str_replace('.', ',', (string) $loan->thm) : '',
        'months' => $loan?->remaining_months !== null ? (string) $loan->remaining_months : '',
        'mode' => ($loan->prepay_mode ?? PrepayMode::ReduceTerm)->value,
    ];
@endphp

<div x-data="loanPage({ decimals: @js($currency->decimals()), locale: @js(app()->getLocale()), details: @js($details), hasLoan: @js($loan !== null), openOnStart: @js($loan === null && auth()->user()->hasPlusz()), saved: @js(Money::toInput($summary?->saved() ?: null, $currency)), savedAmount: @js($summary?->saved() ?? 0), step: @js(Money::toInput($this->step, $currency)) })">
    <x-pages::settings.layout :heading="__('When will I be loan-free?')" back="month">
        @unless (auth()->user()->hasPlusz())
            <x-ui.card class="p-[18px]" data-test="loan-plusz">
                <div class="flex items-center gap-3">
                    <x-morzsa mood="kivancsi" :size="56" />
                    <div class="min-w-0">
                        <span class="rounded-full bg-plum-tint px-2 py-0.5 text-[11px] font-semibold text-plum">Plusz</span>
                        <p class="mt-1 text-[17px] font-semibold">{{ __('What is in Plusz?') }}</p>
                    </div>
                </div>
                <p class="mt-2 text-[15px] text-ink-2">{{ __('It shows when your loan runs out if you set aside a little for prepaying it each month.') }}</p>
                <x-plusz-info class="mt-4" />
                <x-ui.button variant="tonal" size="md" :href="route('subscription')" wire:navigate class="mt-4 w-full" data-test="to-subscription">{{ __('Switch to Plusz') }}</x-ui.button>
            </x-ui.card>
        @else
            @if ($summary === null)
                <x-ui.card class="p-[18px]" data-test="loan-empty">
                    <div class="flex items-center gap-3">
                        <x-ui.icon-tile icon="account_balance" :size="36" />
                        <div class="min-w-0">
                            <div class="truncate text-[15px] font-semibold">{{ $line->category->name ?? '' }}</div>
                            <div class="text-[13px] text-muted">{{ __('monthly :amount', ['amount' => money($line->amount)]) }}</div>
                        </div>
                    </div>
                    <p class="mt-3 text-[15px] leading-relaxed text-ink-2">{{ __('Tell me how much is left of it, and I show when it runs out and what a monthly prepayment saving would change.') }}</p>
                    <x-ui.button variant="tonal" size="md" x-on:click="openDetails()" class="mt-4 w-full" data-test="loan-add-details">{{ __('Add the loan’s details') }}</x-ui.button>
                </x-ui.card>
            @else
                {{-- The loan in short, and when it runs out as it is. --}}
                <x-ui.card class="p-[18px]" data-test="loan-summary">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-[13px] text-muted">{{ __(':name · :left · monthly :installment', ['name' => $summary->loan->name, 'left' => money($summary->loan->principal_balance), 'installment' => money($summary->loan->monthlyPayment())]) }}</div>
                            <div class="mt-1 font-display text-[22px] font-bold" data-test="loan-end">{{ __('Runs out: :month', ['month' => Dates::yearMonth($summary->plan->endWith)]) }}</div>
                        </div>
                        <x-ui.icon-button icon="edit" x-on:click="openDetails()" :label="__('The loan’s details')" data-test="loan-edit-details" />
                    </div>
                    <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-[13px]">
                        <div><dt class="text-muted">{{ __('Rate (THM)') }}</dt><dd class="num mt-0.5 font-medium">{{ str_replace('.', ',', (string) ($summary->loan->thm ?? 0)) }}%</dd></div>
                        <div><dt class="text-muted">{{ __('Months left') }}</dt><dd class="num mt-0.5 font-medium">{{ $summary->loan->remaining_months }}</dd></div>
                        <div><dt class="text-muted">{{ __('Interest still') }}</dt><dd class="num mt-0.5 font-medium">{{ __('c. :amount', ['amount' => money((int) (round($summary->plan->interestWith / 1000) * 1000))]) }}</dd></div>
                    </dl>
                </x-ui.card>

                {{-- The prepayment being saved up. --}}
                @if ($summary->isSaving())
                    <x-ui.card @class(['mt-3 p-[18px]', 'bg-plum-tint' => $summary->isReady()]) data-test="loan-progress">
                        @if ($summary->isReady())
                            <div class="flex items-center gap-3">
                                <x-morzsa mood="unneplo" :size="56" pouch />
                                <div class="min-w-0">
                                    <div class="font-display text-xl font-bold">{{ __(':amount is there!', ['amount' => app()->getLocale() === 'hu' ? \App\Support\Hungarian::withArticle(money($summary->saved())) : money($summary->saved())]) }}</div>
                                    <div class="text-[13px] text-ink-2">{{ __('You can prepay in your bank now.') }}</div>
                                </div>
                            </div>
                        @else
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-[13px] font-semibold">{{ __('Next prepayment') }}</span>
                                <span class="num text-[13px] text-ink-2">{{ money_number($summary->saved()) }} / {{ money($summary->step()) }}</span>
                            </div>
                            <x-ui.bar :value="$summary->saved() / max(1, $summary->step())" :label="__('Next prepayment')" class="mt-2" />
                        @endif
                        <p class="mt-2 text-xs text-muted">{{ __('The app moves no money. Once it is done in the bank, just note it here.') }}</p>
                        <x-ui.button :variant="$summary->isReady() ? 'tonal' : 'secondary'" size="md" x-on:click="openPrepay()" class="mt-3 w-full" data-test="loan-prepaid">{{ __('I prepaid') }}</x-ui.button>
                    </x-ui.card>
                @endif

                {{-- The planner: a monthly saving and what it changes. --}}
                <x-ui.card class="mt-3 p-[18px]" data-test="loan-planner">
                    <p class="text-[15px] font-semibold">{{ __('Each month I set aside for prepayment:') }}</p>
                    <div class="num mt-1 font-display text-[28px] font-bold" data-test="loan-monthly">{{ money($this->monthly) }}</div>
                    <input type="range" min="0" max="{{ $slider['max'] }}" step="{{ $slider['step'] }}" wire:model.live.debounce.150ms="monthly" class="mt-2 w-full accent-[var(--color-accent)]" aria-label="{{ __('Each month I set aside for prepayment:') }}" data-test="loan-slider">
                    <div class="num flex justify-between text-xs text-muted"><span>{{ money(0) }}</span><span>{{ money($slider['max']) }}</span></div>

                    @if ($preview !== null)
                        <div class="mt-4 rounded-card bg-surface-2 p-3.5" data-test="loan-result">
                            <div class="text-[13px] text-muted">{{ __('It then runs out:') }}</div>
                            <div class="mt-0.5 font-display text-[22px] font-bold">{{ Dates::yearMonth($preview->plan->endWith) }}</div>
                            @if ($preview->plan->monthsSooner() > 0)
                                <p class="mt-1 text-[13px] font-medium text-accent-strong">{{ $sooner($preview->plan->monthsSooner()) }} · {{ __('you save c. :amount interest', ['amount' => money((int) (round($preview->plan->savedInterest() / 1000) * 1000))]) }}</p>
                            @elseif ($this->monthly > 0 && $preview->plan->savedInterest() > 0)
                                <p class="mt-1 text-[13px] font-medium text-accent-strong">{{ __('you save c. :amount interest', ['amount' => money((int) (round($preview->plan->savedInterest() / 1000) * 1000))]) }}</p>
                            @endif
                            @if ($this->monthly > 0)
                                <p class="mt-1 text-xs text-muted">{{ __('You prepay every :step, about every :months months.', ['step' => money($this->step), 'months' => (int) ceil($this->step / max(1, $this->monthly))]) }}</p>
                                @if ($preview->plan->installmentAfterFirst !== null)
                                    <p class="mt-1 text-xs text-muted">{{ __('After the first prepayment the installment is about :amount.', ['amount' => money($preview->plan->installmentAfterFirst)]) }}</p>
                                @endif
                            @endif

                            {{-- With and without the saving, as two bars. --}}
                            @php
                                $start = $this->today()->startOfMonth();
                                $without = max(1, (int) $start->diffInMonths($preview->plan->endWithout) + 1);
                                $with = max(1, (int) $start->diffInMonths($preview->plan->endWith) + 1);
                            @endphp
                            <div class="mt-3 grid gap-1.5 text-xs" aria-hidden="true">
                                <div class="flex items-center gap-2"><i class="block h-2 rounded-full bg-accent" style="width: {{ round($with / $without * 55) }}%"></i><span class="whitespace-nowrap text-ink-2">{{ $this->monthly > 0 ? __('with :amount a month', ['amount' => money($this->monthly)]) : __('as it is') }}</span></div>
                                <div class="flex items-center gap-2"><i class="block h-2 w-[55%] rounded-full bg-surface-3"></i><span class="whitespace-nowrap text-muted">{{ __('without saving') }}</span></div>
                            </div>
                        </div>
                    @endif

                    <x-ui.form-group class="mt-3">
                        <button type="button" x-on:click="openStep()" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-left text-[15px]" data-test="loan-step">
                            <span>{{ __('Prepay every') }}</span><span class="num font-semibold text-accent">{{ money($this->step) }}</span>
                        </button>
                    </x-ui.form-group>
                    <p class="px-1 pt-3 pb-2 text-[13px] font-medium text-muted">{{ __('When you prepay, what should happen?') }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ([PrepayMode::ReduceTerm->value => __('Runs out sooner'), PrepayMode::ReduceInstallment->value => __('Smaller installment')] as $value => $label)
                            <x-ui.choice :selected="$this->mode === $value" wire:click="$set('mode', '{{ $value }}')" class="min-h-11 rounded-control px-3 text-[15px]" data-test="loan-mode-{{ $value }}">{{ $label }}</x-ui.choice>
                        @endforeach
                    </div>

                    @if ($this->monthly > 0)
                        <p class="mt-3 text-xs text-muted">{{ __('The :amount is taken off the month’s money in advance, like a fixed expense.', ['amount' => money($this->monthly)]) }}</p>
                    @endif
                    <x-ui.button variant="tonal" size="md" wire:click="savePlan" class="mt-3 w-full" data-test="loan-save-plan">
                        {{ $this->monthly > 0 ? __('This is what I want') : ($summary->monthlySaving > 0 ? __('Stop the saving') : __('This is what I want')) }}
                    </x-ui.button>
                </x-ui.card>

                @if ($this->prepayments->isNotEmpty())
                    <x-ui.section-label class="mt-5" :label="__('Prepayments')" />
                    <x-ui.card class="px-4" data-test="loan-prepayments">
                        @foreach ($this->prepayments as $event)
                            <div wire:key="prepayment-{{ $event->id }}" @class(['flex items-center justify-between gap-3 py-3', 'border-b border-line' => ! $loop->last])>
                                <div>
                                    <div class="text-[15px]">{{ __(':number. prepayment', ['number' => $loop->iteration]) }}</div>
                                    <div class="text-xs text-muted">{{ $event->occurred_on->year }}. {{ Dates::short($event->occurred_on) }}.</div>
                                </div>
                                <x-ui.amount size="sm" :value="$event->amount" class="text-[15px] font-medium" />
                            </div>
                        @endforeach
                    </x-ui.card>
                @endif

                <p class="mt-4 px-1.5 text-xs text-muted">{{ __('Estimates from the rate and the installment; your bank’s numbers may differ a little.') }}</p>
            @endif
        @endunless
    </x-pages::settings.layout>

    {{-- The loan's details. --}}
    <x-ui.sheet show="sheet === 'details'" close="sheet = null" title="detailsTitle()" data-test="loan-details-sheet">
        <x-ui.form-group>
            <x-ui.amount-row name="principal" :label="__('Balance still owed')" :hint="__('How much is left of it?')" />
            <x-ui.text-row :label="__('Rate (THM, %)')" x-model="form.thm" inputmode="decimal" maxlength="6" placeholder="9,9" data-test="loan-thm" />
            <x-ui.text-row :label="__('Months left')" x-model="form.months" inputmode="numeric" maxlength="3" placeholder="55" data-test="loan-months" />
        </x-ui.form-group>
        <p class="px-1.5 pt-2 text-xs text-muted">{{ __('The rate is in your contract or in your bank’s app. The installment is the fixed line’s amount: :amount.', ['amount' => money($line->amount)]) }}</p>
        <p class="px-1 pt-3 pb-2 text-[13px] font-medium text-muted">{{ __('When you prepay, what should happen?') }}</p>
        <div class="grid grid-cols-2 gap-2">
            @foreach ([PrepayMode::ReduceTerm->value => __('Runs out sooner'), PrepayMode::ReduceInstallment->value => __('Smaller installment')] as $value => $label)
                <x-ui.choice x-on:click="form.mode = '{{ $value }}'" ::selected="form.mode === '{{ $value }}'" class="min-h-11 rounded-control px-3 text-[15px]">{{ $label }}</x-ui.choice>
            @endforeach
        </div>
        <p class="px-1.5 pt-2 text-xs text-danger" x-show="error" x-text="error"></p>
        <x-slot:footer>
            <x-ui.button x-on:click="saveDetails()" class="w-full" data-test="loan-save-details">{{ __('Save') }}</x-ui.button>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="$currency->decimals() > 0" /></x-slot:pad>
    </x-ui.sheet>

    <x-ui.numpad-sheet show="sheet === 'step'" close="sheet = null" :label="__('Prepay every')" done="saveStep()" display="display('step')" :decimal="$currency->decimals() > 0" error="error" data-test="loan-step-sheet" />

    <x-ui.numpad-sheet show="sheet === 'prepay'" close="sheet = null" :label="__('I prepaid')" done="savePrepay()" display="display('prepay')" :decimal="$currency->decimals() > 0" error="error"
                       intro="prepayHint()" :done-label="__('Save')" data-test="loan-prepay-sheet" />
</div>

@script
<script>
    Alpine.data('loanPage', ({ decimals, locale, details, hasLoan, openOnStart, saved, savedAmount, step }) => ({
        ...window.amountFields({ decimals, locale, fields: { principal: details.principal, step, prepay: '' }, rules: { principal: { maxWhole: 10 }, step: { maxWhole: 9 }, prepay: { maxWhole: 10 } } }),
        sheet: null,
        form: { thm: details.thm, months: details.months, mode: details.mode },
        error: null,

        init() {
            if (openOnStart) this.openDetails()
        },
        detailsTitle() {
            return hasLoan ? @js(__('The loan’s details')) : @js(__('New loan'));
        },
        openDetails() {
            this.error = null
            this.active = null
            this.sheet = 'details'
        },
        async saveDetails() {
            this.active = null
            const result = await $wire.saveDetails({ principal: this.fields.principal || '', thm: this.form.thm || '', months: this.form.months || '', mode: this.form.mode })
            this.error = Object.values(result.errors ?? {}).join(' ') || null
            if (result.ok) {
                hasLoan = true
                this.sheet = null
            }
        },
        openStep() {
            this.editAmount('step', this.fields.step, '')
            this.error = null
            this.sheet = 'step'
        },
        async saveStep() {
            const result = await $wire.setStep(this.fields.step || '0')
            this.error = result.error
            if (result.ok) this.sheet = null
        },
        openPrepay() {
            this.editAmount('prepay', saved, '')
            this.error = null
            this.sheet = 'prepay'
        },
        prepayHint() {
            const typed = parseFloat(String(this.fields.prepay || '0').replace(',', '.')) * (10 ** decimals) || 0
            if (savedAmount <= 0) return @js(__('Noted with today’s date.'));
            return typed <= savedAmount ? @js(__('Taken from what you set aside for it.')) : @js(__('More than what is set aside, so the saving stays as it is.'));
        },
        async savePrepay() {
            const result = await $wire.recordPrepayment(this.fields.prepay || '0')
            this.error = result.error
            if (result.ok) this.sheet = null
        },
    }))
</script>
@endscript
