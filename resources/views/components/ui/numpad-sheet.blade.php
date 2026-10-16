@props(['show', 'close', 'done', 'title' => null, 'label' => null, 'display' => "display()", 'decimal' => false, 'error' => null, 'intro' => null, 'symbol' => null, 'symbolBind' => null, 'doneLabel' => null])

{{--
    A single amount on its own sheet (onboarding answers, actual income, the leftover split):
    title, the typed amount with its currency, the numpad, and Done at the bottom — the same
    layout as x-ui.amount-pad over a form. done: the Alpine statement Done and Enter run;
    error / intro: Alpine expressions for a message under the amount / a line under the title.
--}}
<x-ui.sheet :show="$show" :close="$close" :title="$title" :label="$label" :full="false" {{ $attributes }}>
    @if ($intro)
        <p class="px-2 pb-1 text-center text-[13px] leading-snug text-muted" x-show="{{ $intro }}" x-text="{{ $intro }}" data-test="numpad-intro"></p>
    @endif
    <x-ui.amount-display :display="$display" :label="$label" :label-bind="$title" :symbol="$symbol" :symbol-bind="$symbolBind" class="py-4" />
    @if ($error)
        <div class="mb-2 text-center text-xs text-danger" x-show="{{ $error }}" x-text="{{ $error }}" role="alert"></div>
    @endif
    <x-ui.numpad :decimal="$decimal" :enter="$done" />
    <x-slot:footer>
        <x-ui.button x-on:click="{{ $done }}" class="w-full" data-test="numpad-done">{{ $doneLabel ?? __('Done') }}</x-ui.button>
    </x-slot:footer>
</x-ui.sheet>
