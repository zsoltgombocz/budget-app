@props(['category'])

@php
    /** @var \App\Services\Data\CategoryForecast $category */
    $percent = $category->planned > 0 ? min(100, (int) round($category->spent / $category->planned * 100)) : ($category->spent > 0 ? 100 : 0);
    $bar = match (true) {
        $category->isOver() => 'bg-red-500',
        $category->isWarning() => 'bg-amber-500',
        default => 'bg-emerald-500',
    };
@endphp

<a href="{{ route('month', ['kategoria' => $category->categoryId]) }}" wire:navigate {{ $attributes->class(['block rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-700/50']) }}>
    <div class="flex items-baseline justify-between gap-2 text-sm">
        <span class="truncate font-medium">{{ $category->categoryName }}</span>
        <span class="shrink-0 text-xs text-zinc-500">
            <x-money :amount="$category->spent" /> / <x-money :amount="$category->planned" />
        </span>
    </div>
    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $category->categoryName }}">
        <div class="h-full rounded-full {{ $bar }}" style="width: {{ $percent }}%"></div>
    </div>
</a>
