@props(['name', 'size' => 24, 'fill' => false, 'weight' => 400])

<span {{ $attributes->class(['ms shrink-0', 'ms-fill' => $fill]) }} style="font-size: {{ $size }}px; font-weight: {{ $weight }}; width: {{ $size }}px; height: {{ $size }}px;" aria-hidden="true">{{ $name }}</span>
