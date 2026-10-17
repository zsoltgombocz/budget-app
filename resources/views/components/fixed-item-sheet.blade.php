{{--
    Options for one fixed item, opened by x-fixed-item-row with
    $dispatch('open-fixed-item', { id, name, amount, status, note, doneLabel }): tick it off
    (tonal, the main action) or skip it this month (secondary), or set a skipped one back (then
    it can be ticked off as usual). A short sheet that grows with its content. Calls the page's
    fixed item methods (togglePaid, skipLine, unskipLine); each answers with a toast that can undo it.
--}}
<div x-data="{ item: null }" x-on:open-fixed-item.window="item = $event.detail">
    <x-ui.sheet show="item !== null" close="item = null" title="item?.name" :full="false" data-test="fixed-item-sheet">
        <div class="px-1.5 pb-2 text-center">
            <div class="num text-[22px] font-semibold" :class="item?.status === 'skipped' && 'text-muted line-through'" x-text="item?.amount"></div>
            <p class="mt-2 text-[13px] leading-snug text-muted" x-text="item?.note"></p>
        </div>
        <div class="mt-3 grid gap-2">
            <template x-if="item && item.status === 'open'">
                <x-ui.button variant="tonal" size="md" icon="check" class="w-full" x-on:click="$wire.togglePaid(item.id); item = null" data-test="sheet-done"><span x-text="item.doneLabel ?? @js(__('Done'))"></span></x-ui.button>
            </template>
            <template x-if="item && item.status === 'open'">
                <x-ui.button variant="secondary" size="md" icon="event_busy" class="w-full" x-on:click="$wire.skipLine(item.id); item = null" data-test="skip-line">{{ __('Skipped this month') }}</x-ui.button>
            </template>
            <template x-if="item?.status === 'skipped'">
                <x-ui.button variant="secondary" size="md" icon="undo" class="w-full" x-on:click="$wire.unskipLine(item.id); item = null" data-test="due-again">{{ __('It comes up after all') }}</x-ui.button>
            </template>
        </div>
    </x-ui.sheet>
</div>
