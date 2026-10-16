@props(['item', 'today', 'open' => true])

{{--
    One fixed item in a checklist (Today, Month): tap to tick it off. A plain fixed item that is
    not ticked off also has an options button for skipping it this month; a skipped row opens the
    same options on tap (it comes up after all) instead of ticking. A ticked-off item cannot be
    skipped. Needs <x-fixed-item-sheet /> on the page and the fixed item methods on the component.
--}}
@php
    $status = $item->status();
    $skipped = $status === \App\Enums\LineStatus::Skipped;
    $overdue = $item->isOverdue($today);
    $lineId = (int) $item->line->lineId;
    $options = $open && $item->line->isSkippable() && ! $item->paid;
    $isTransfer = in_array($item->line->type, [\App\Enums\LineType::Transfer, \App\Enums\LineType::Sinking], true);
    // A monthly saving into a pocket is not a bill: the closing adds it to the pocket.
    $isSaving = $item->line->type === \App\Enums\LineType::Sinking && $item->line->pocketId !== null;
    $detail = [
        'id' => $lineId,
        'name' => $item->line->categoryName,
        'amount' => money($item->line->planned()),
        'status' => $status->value,
        'note' => $skipped
            ? __('Skipped this month: it counts as 0, so :amount more is left.', ['amount' => money($item->line->planned())])
            : __('Does it not come up this month? Then it counts as 0, so :amount more is left. You can set it back any time.', ['amount' => money($item->line->planned())]),
    ];
@endphp

<div {{ $attributes->class(['flex items-center gap-1', 'opacity-60' => $item->paid]) }} data-test="fixed-item-row" data-status="{{ $status->value }}">
    <button type="button"
            @if ($skipped && $options) x-on:click="$dispatch('open-fixed-item', @js($detail))" aria-haspopup="dialog"
            @else role="checkbox" aria-checked="{{ $item->paid ? 'true' : 'false' }}" wire:click="togglePaid({{ $lineId }})" @endif
            @disabled(! $open)
            class="focus-ring grid min-h-11 min-w-0 flex-1 grid-cols-[26px_minmax(0,1fr)_auto] items-center gap-3 rounded-xl py-[11px] text-left" data-test="fixed-item">
        @if ($item->paid)
            <span class="flex size-6 items-center justify-center rounded-full bg-accent/16 text-accent" aria-hidden="true"><x-ui.icon name="check" :size="16" :weight="600" /></span>
        @elseif ($skipped)
            <span class="flex size-6 items-center justify-center rounded-full bg-surface-3 text-muted" aria-hidden="true"><x-ui.icon name="remove" :size="16" :weight="600" /></span>
        @else
            <span @class(['size-6 rounded-full border-[1.5px] border-dashed', 'border-warn' => $overdue, 'border-faint' => ! $overdue]) aria-hidden="true"></span>
        @endif
        <span class="min-w-0">
            <span @class(['block truncate text-[15px]', 'text-ink-2' => $item->paid, 'text-muted' => $skipped])>{{ $item->line->categoryName }}</span>
            <span @class(['mt-0.5 block text-xs', 'text-warn' => $overdue, 'text-muted' => ! $overdue]) data-test="fixed-item-state">
                @if ($item->paid)
                    {{ $isTransfer ? __('Transferred this month') : __('Paid this month') }}
                @elseif ($skipped)
                    {{ __('skipped – counted as :zero', ['zero' => money(0)]) }}
                @elseif ($item->dueOn)
                    {{ $overdue ? __('was due :date', ['date' => \App\Support\Dates::short($item->dueOn)]) : __('due :date', ['date' => \App\Support\Dates::short($item->dueOn)]) }}
                @elseif ($isSaving)
                    {{ __('Monthly saving, goes into the pocket at closing') }}
                @elseif ($isTransfer)
                    {{ __('Tap once you have transferred it') }}
                @else
                    {{ __('Tap once you have paid it') }}
                @endif
            </span>
        </span>
        <span>
            <x-ui.amount size="sm" :value="$item->line->planned()" @class(['text-[15px] font-medium', 'text-muted line-through' => $skipped]) />
            @if ($skipped)<span class="sr-only">{{ __('counts as 0') }}</span>@endif
        </span>
    </button>
    @if ($options)
        <button type="button" x-on:click="$dispatch('open-fixed-item', @js($detail))" aria-haspopup="dialog"
                class="focus-ring -mr-2 flex size-11 shrink-0 items-center justify-center rounded-xl text-muted"
                aria-label="{{ __('More for :name', ['name' => $item->line->categoryName]) }}" data-test="fixed-item-options">
            <x-ui.icon name="more_horiz" :size="20" />
        </button>
    @endif
</div>
