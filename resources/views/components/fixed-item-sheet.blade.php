{{--
    Options for one fixed item, opened by x-fixed-item-row with
    $dispatch('open-fixed-item', { id, name, amount, status, note }). Calls the page's
    SetsFixedItemStatus methods; each answers with a toast that can undo it.
--}}
<div x-data="{ item: null }" x-on:open-fixed-item.window="item = $event.detail">
    <x-ui.sheet show="item !== null" close="item = null" title="item?.name" :full="false" data-test="fixed-item-sheet">
        <div class="px-1.5 pb-2 text-center">
            <div class="num text-[22px] font-semibold" :class="item?.status === 'skipped' && 'text-muted line-through'" x-text="item?.amount"></div>
            <p class="mt-2 text-[13px] leading-snug text-muted" x-text="item?.note"></p>
        </div>
        <div class="mt-3 grid gap-2">
            <template x-if="item && item.status !== 'skipped'">
                <x-ui.button variant="secondary" size="md" icon="event_busy" class="w-full" x-on:click="$wire.skipLine(item.id); item = null" data-test="skip-line">{{ __('Skip this month') }}</x-ui.button>
            </template>
            <template x-if="item?.status === 'skipped'">
                <div class="grid gap-2">
                    <x-ui.button variant="secondary" size="md" icon="check" class="w-full" x-on:click="$wire.togglePaid(item.id); item = null" data-test="paid-after-all">{{ __('Paid after all') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="md" icon="undo" class="w-full" x-on:click="$wire.unskipLine(item.id); item = null" data-test="due-again">{{ __('Due again') }}</x-ui.button>
                </div>
            </template>
        </div>
    </x-ui.sheet>
</div>
