@props(['decimal' => false, 'fill' => false])

{{--
    On-screen numpad (no system keyboard). Calls press(key) in the surrounding Alpine scope,
    usually the amountFields() helper (resources/js/numpad.js). decimal: a comma key instead of
    000. fill: the keys share the height they get (min. 36px) instead of a fixed 50px, for
    sheets that have to fit short screens.
--}}
<div {{ $attributes->class(['grid grid-cols-3 gap-1.5', 'grid-rows-4' => $fill]) }}>
    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', $decimal ? ',' : '000', '0', 'del'] as $key)
        <button type="button" x-on:click="press(@js($key))" data-key="{{ $key }}"
                @class([
                    'num flex items-center justify-center rounded-2xl bg-ink/4 text-[26px] font-medium transition active:bg-ink/12 focus-ring',
                    'min-h-[50px]' => ! $fill,
                    'h-full min-h-9' => $fill,
                ])
                aria-label="{{ $key === 'del' ? __('Delete') : $key }}">
            @if ($key === 'del')
                <x-ui.icon name="backspace" :size="24" />
            @else
                {{ $key }}
            @endif
        </button>
    @endforeach
</div>
