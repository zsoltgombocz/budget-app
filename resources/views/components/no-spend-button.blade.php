@props(['marked' => false])

{{--
    "I didn't spend today" on the dashboard. Unmarked: a text button with the do_not_disturb_on
    icon. Marked: it stays in place as a row (filled check, "You didn't spend today") with an
    Undo text button, so it is always visible and can always be undone. Both are 44px tall and
    fit a full-width row (e.g. under a border-t at the bottom of a card: Undo moves to the right)
    as well as a centred spot. Calls markNoSpend / unmarkNoSpend on the page component.
--}}
@if ($marked)
    <div {{ $attributes->class(['flex min-h-11 items-center gap-2 text-[15px]']) }} data-test="no-spend-marked">
        <x-ui.icon name="check_circle" :size="20" :fill="true" class="text-accent" />
        <span class="min-w-0 font-medium text-ink-2">{{ __("You didn't spend today") }}</span>
        <x-ui.button variant="text" size="md" wire:click="unmarkNoSpend" class="-mr-1 ml-auto" data-test="undo-no-spend">{{ __('Undo') }}</x-ui.button>
    </div>
@else
    <x-ui.button variant="text" size="md" icon="do_not_disturb_on" wire:click="markNoSpend" {{ $attributes }} data-test="no-spend">{{ __("I didn't spend today") }}</x-ui.button>
@endif
