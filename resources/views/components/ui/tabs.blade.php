@props(['model', 'options', 'id'])

{{--
    Underline tabs under a header or at the top of a sheet (e.g. the pocket sheet's
    "Move money / Settings"), bound to the Alpine expression `model`; options is value => label.
    Use them to switch between views of one thing; for choosing a value use x-ui.segmented
    (2–3 short options) or x-ui.choice chips.

    id: a prefix for the ARIA wiring. Tab "x" gets id="{id}-tab-x" and aria-controls="{id}-panel-x";
    give each panel that id, role="tabpanel" and aria-labelledby, e.g.
        <x-ui.tabs id="pocket" model="pocketTab" :options="['money' => __('Move money'), 'settings' => __('Settings')]" />
        <div id="pocket-panel-money" role="tabpanel" aria-labelledby="pocket-tab-money" x-show="pocketTab === 'money'">…</div>
    Left/Right arrows move between tabs; only the selected tab is in the Tab order.
--}}
{{--
    The underline is one bar that slides to the selected tab (200ms). Until it has been measured
    (a tablist inside a closed sheet has no size yet) each tab draws its own underline instead.
--}}
<div role="tablist"
     x-data="{
        tabLeft: 0,
        tabWidth: 0,
        tabSlide: false,
        placeTab() {
            const tab = this.$root.querySelector('[role=tab][aria-selected=true]')
            if (! tab || ! tab.offsetWidth) return
            this.tabSlide = this.tabWidth > 0
            this.tabLeft = tab.offsetLeft
            this.tabWidth = tab.offsetWidth
        },
     }"
     x-init="new ResizeObserver(() => placeTab()).observe($el)"
     x-effect="{{ $model }}; $nextTick(() => placeTab())"
     {{ $attributes->class(['relative flex gap-6 border-b border-line']) }}>
    @foreach ($options as $value => $label)
        <button type="button" role="tab" id="{{ $id }}-tab-{{ $value }}" aria-controls="{{ $id }}-panel-{{ $value }}"
                x-on:click="{{ $model }} = @js($value)"
                :aria-selected="{{ $model }} === @js($value) ? 'true' : 'false'" :tabindex="{{ $model }} === @js($value) ? 0 : -1"
                x-on:keydown.right.prevent="$el.nextElementSibling?.click(); $nextTick(() => $el.nextElementSibling?.focus())"
                x-on:keydown.left.prevent="$el.previousElementSibling?.click(); $nextTick(() => $el.previousElementSibling?.focus())"
                class="focus-ring relative -mb-px h-11 truncate rounded-t-lg text-[15px] font-medium transition-colors duration-200 after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:rounded-full"
                :class="{{ $model }} === @js($value) ? (tabWidth ? 'text-ink after:bg-transparent' : 'text-ink after:bg-accent') : 'text-muted after:bg-transparent'"
                data-test="tabs-{{ $value }}">{{ $label }}</button>
    @endforeach
    <span aria-hidden="true" x-show="tabWidth > 0" x-cloak
          class="pointer-events-none absolute -bottom-px left-0 h-0.5 rounded-full bg-accent ease-out"
          :class="tabSlide && 'transition-[transform,width] duration-200'"
          :style="`width: ${tabWidth}px; transform: translateX(${tabLeft}px)`" data-test="tabs-indicator"></span>
</div>
