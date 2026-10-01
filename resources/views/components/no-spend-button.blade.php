@props(['marked' => false])

{{-- "I didn't spend today" on the dashboard; once marked it stays visible and can be undone. --}}
@if ($marked)
    <div class="flex h-11 items-center gap-2 rounded-[14px] bg-accent/12 pl-3.5 pr-1.5 text-sm font-medium text-accent" data-test="no-spend-marked">
        <x-ui.icon name="check_circle" :size="18" :fill="true" />
        {{ __("You didn't spend today") }}
        <button type="button" wire:click="unmarkNoSpend" class="h-8 rounded-[10px] px-2.5 font-semibold text-ink-2 hover:bg-ink/6" data-test="undo-no-spend">{{ __('Undo') }}</button>
    </div>
@else
    <x-ui.button variant="secondary" size="md" icon="do_not_disturb_on" wire:click="markNoSpend" class="[&_.ms]:text-accent" data-test="no-spend">{{ __("I didn't spend today") }}</x-ui.button>
@endif
