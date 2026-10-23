@props(['keys' => []])

@php
    /*
     * The milestone celebration (S16): full screen, one milestone at a time, skippable, a still image
     * when motion is reduced. Shown once: Rewards::takeCelebrations() marks them celebrated.
     */
    $milestones = \App\Services\Rewards::milestones();
    $queue = array_values(array_map(fn (string $key): array => [
        'title' => $milestones[$key]['title'],
        'text' => $milestones[$key]['text'],
        'acorns' => $milestones[$key]['acorns'],
    ], array_filter($keys, fn (string $key): bool => isset($milestones[$key]))));
@endphp

@if ($queue !== [])
    <div x-data="{ queue: @js($queue), get current() { return this.queue[0] ?? null } }" x-show="current" x-cloak
         class="fixed inset-0 z-[60] flex flex-col items-center bg-bg bg-[radial-gradient(240px_260px_at_50%_40%,var(--app-plum-tint),transparent)] px-6 pt-[calc(var(--safe-top)+64px)] pb-[calc(24px+env(safe-area-inset-bottom))] text-center"
         role="dialog" aria-modal="true" aria-labelledby="celebration-title" data-test="celebration">
        <svg class="size-[88px]" viewBox="0 0 72 72" aria-hidden="true"><path d="M36 6 L62 21 L62 51 L36 66 L10 51 L10 21 Z" class="fill-plum-tint stroke-plum" stroke-width="3" stroke-linejoin="round"/></svg>
        <x-morzsa mood="unneplo" :size="190" class="mt-2" />
        <h1 id="celebration-title" class="mt-5 font-display text-[32px] leading-9 font-bold tracking-[-0.02em]" x-text="current?.title"></h1>
        <p class="mt-2 text-[17px] leading-relaxed text-ink-2" x-text="current?.text"></p>
        <span class="mt-4 inline-flex h-8 items-center gap-1.5 rounded-full bg-plum-tint px-3 text-sm font-semibold text-plum" x-text="'+' + current?.acorns + ' ' + @js(__('acorns'))"></span>
        <div class="flex-1"></div>
        <x-ui.button class="w-full" x-on:click="queue.shift()" data-test="celebration-next">{{ __('Next') }}</x-ui.button>
    </div>
@endif
