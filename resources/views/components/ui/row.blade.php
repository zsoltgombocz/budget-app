@props(['last' => false])

<div {{ $attributes->class(['py-[13px]', 'border-b border-line' => ! $last]) }}>{{ $slot }}</div>
