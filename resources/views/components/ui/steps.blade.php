@props(['current', 'total' => 3])

<div {{ $attributes->class(['grid gap-1.5 px-6 pt-2']) }} style="grid-template-columns: repeat({{ $total }}, minmax(0, 1fr))">
    @for ($i = 1; $i <= $total; $i++)
        <div @class(['h-1 rounded-sm', 'bg-accent' => $i <= $current, 'bg-ink/10' => $i > $current])></div>
    @endfor
</div>
