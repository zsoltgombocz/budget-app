@props(['summary'])

{{-- "Következő előtörlesztés" (S23): a loan's prepayment being saved up, on Today and Month; it opens the planner. --}}
@php
    /** @var \App\Services\Data\LoanSummary $summary */
    $lineId = $summary->line?->id;
@endphp
<a @if ($lineId) href="{{ route('loan', $lineId) }}" wire:navigate @endif {{ $attributes->class(['focus-ring block rounded-card p-4', 'bg-plum-tint' => $summary->isReady(), 'bg-surface' => ! $summary->isReady()]) }} data-test="loan-progress-card">
    <div class="flex items-baseline justify-between gap-2">
        <span class="text-[13px] font-semibold">{{ $summary->isReady() ? __(':amount is there!', ['amount' => app()->getLocale() === 'hu' ? \App\Support\Hungarian::withArticle(money($summary->saved())) : money($summary->saved())]) : __('Next prepayment') }}</span>
        @unless ($summary->isReady())
            <span class="num text-[13px] text-ink-2">{{ money_number($summary->saved()) }} / {{ money($summary->step()) }}</span>
        @endunless
    </div>
    @unless ($summary->isReady())
        <x-ui.bar :value="$summary->saved() / max(1, $summary->step())" :label="__('Next prepayment')" class="mt-2" />
    @endunless
    <p class="mt-1.5 text-xs text-muted">
        {{ $summary->isReady() ? __('You can prepay in your bank now.').' ' : '' }}{{ $summary->loan->name }} · {{ __('Runs out: :month', ['month' => \App\Support\Dates::yearMonth($summary->plan->endWith)]) }}
    </p>
</a>
