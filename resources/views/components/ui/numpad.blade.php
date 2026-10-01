@props(['decimal' => false])

{{-- On-screen numpad (no system keyboard). Calls press(key) in the surrounding Alpine scope. --}}
<div {{ $attributes->class(['grid grid-cols-3 gap-1.5']) }}>
    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', $decimal ? ',' : '000', '0', 'del'] as $key)
        <button type="button" x-on:click="press(@js($key))" data-key="{{ $key }}"
                class="num flex min-h-[50px] items-center justify-center rounded-2xl bg-ink/4 text-[26px] font-medium transition active:bg-ink/12"
                aria-label="{{ $key === 'del' ? __('Delete') : $key }}">
            @if ($key === 'del')
                <x-ui.icon name="backspace" :size="24" />
            @else
                {{ $key }}
            @endif
        </button>
    @endforeach
</div>
