@props(['as' => 'div'])

<{{ $as }} {{ $attributes->class(['block bg-surface rounded-card']) }}>{{ $slot }}</{{ $as }}>
