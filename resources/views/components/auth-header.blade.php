@props([
    'title',
    'description',
])

<div class="flex w-full flex-col">
    <h1 class="text-[26px] font-semibold leading-tight tracking-[-0.03em]">{{ $title }}</h1>
    <p class="mt-1.5 text-sm leading-normal text-muted">{{ $description }}</p>
</div>
