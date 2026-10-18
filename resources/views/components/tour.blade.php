@props(['section', 'label', 'steps', 'open' => false, 'next' => null, 'upcoming' => []])

{{--
    The "How it works" spotlight tour of one screen (behaviour: resources/js/tour.js, steps:
    App\Support\Tour). A dim scrim covers the app with a cut-out around the element being
    explained (data-tour="{id}" on the page); the card next to it has the step's title and
    text, the step counter, Back / Next and Skip. It starts when `open` is true (Today: once
    after onboarding, or ?bemutato=1 from Settings), or by itself when the previous screen's
    tour led here. next: ['label', 'url', 'section'] turns the last step's button into "on to
    the next screen". upcoming: [section => step count] of the screens still to come, on the
    screen where the tour starts, so the counter runs across the screens ("5 / 15").

    The page underneath cannot be tapped or scrolled by hand while the tour runs. Focus moves
    into the card, Tab stays inside, Escape ends the tour; the scrim is aria-hidden. Transitions
    stop under prefers-reduced-motion and html[data-motion="off"].
--}}
@php
    $config = [
        'section' => $section,
        'steps' => $steps,
        'open' => (bool) $open,
        'next' => $next,
        'upcoming' => (object) $upcoming,
        'seenUrl' => route('intro.seen'),
        'labels' => ['next' => __('Next'), 'done' => __('Done'), 'skip' => __('Skip'), 'finish' => __('Finish'), 'again' => __('You can watch this again any time: Settings → How it works.')],
    ];
    $motion = 'motion-reduce:transition-none [html[data-motion=off]_&]:transition-none';
@endphp

<div x-data="appTour(@js($config))" x-on:keydown.escape.window="tourEscape($event)" class="contents">
    <template x-teleport="body">
        <div x-show="tourActive" x-cloak class="fixed inset-0 z-[60]" data-tour-root data-test="tour">
            {{-- Measures the safe areas for the script. --}}
            <div x-ref="tourSafe" class="pointer-events-none invisible fixed inset-0 pt-[var(--safe-top)] pb-[env(safe-area-inset-bottom)]" aria-hidden="true"></div>

            {{-- Catches every tap and scroll on the page underneath. --}}
            <div class="absolute inset-0 touch-none" x-on:click.prevent x-on:wheel.prevent x-on:touchmove.prevent aria-hidden="true" data-test="tour-scrim"></div>

            {{-- The spotlight: a hole in the scrim (its huge shadow is the scrim), with a thin accent ring. --}}
            {{-- While the page scrolls to the next element the hole is closed at once (tourInstant), so it never trails behind. --}}
            <div class="pointer-events-none absolute left-0 top-0 shadow-[0_0_0_200vmax_var(--tour-scrim),0_0_0_2px_color-mix(in_srgb,var(--app-accent)_75%,transparent)] transition-[left,top,width,height,border-radius] duration-300 ease-out {{ $motion }}"
                 :class="tourInstant && '!transition-none'" :style="tourSpotStyle" aria-hidden="true" data-test="tour-spotlight"></div>

            <div x-ref="tourTip" role="dialog" aria-modal="true" aria-labelledby="tour-{{ $section }}-title" aria-describedby="tour-{{ $section }}-body" tabindex="-1"
                 x-on:keydown.tab="tourTrap($event)"
                 :style="tourTipStyle"
                 :class="tourPlaced ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-1'"
                 class="absolute inset-x-4 mx-auto max-w-[400px] rounded-card bg-surface text-ink shadow-[0_2px_10px_rgba(0,0,0,0.28)] outline-none transition-[opacity,transform,top] duration-200 ease-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent dark:bg-surface-2 {{ $motion }}"
                 data-test="tour-tooltip">
                {{-- Points at the lit element, when the card sits right above or below it. --}}
                <span x-show="tourTip.arrow" class="pointer-events-none absolute size-3.5 -translate-x-1/2 rotate-45 rounded-[3px] bg-surface dark:bg-surface-2"
                      :class="tourTip.arrow === 'top' ? '-top-1.5' : '-bottom-1.5'" :style="`left: ${tourTip.arrowX}px`" aria-hidden="true"></span>

                <div class="relative max-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom)-24px)] overflow-y-auto overscroll-contain px-[18px] pb-4 pt-2">
                    <div class="flex min-h-11 items-center justify-between gap-3">
                        <span class="num text-[13px] font-medium text-muted" data-test="tour-counter">{{ $label }} · <span x-text="tourCounter"></span></span>
                        <x-ui.button variant="text" size="sm" class="-mr-2" x-on:click="tourEnd()" data-test="tour-skip"><span x-text="tourSkipLabel">{{ __('Skip') }}</span></x-ui.button>
                    </div>
                    <h2 id="tour-{{ $section }}-title" class="text-[17px] font-semibold leading-snug tracking-[-0.01em] text-balance" x-text="tourStep.title" data-test="tour-title"></h2>
                    <div id="tour-{{ $section }}-body" class="mt-1.5 space-y-1.5 text-sm leading-snug text-pretty text-ink-2" data-test="tour-body">
                        <template x-for="(line, index) in tourStep.body" :key="tourStep.id + index">
                            <p class="num" x-text="line"></p>
                        </template>
                    </div>
                    <div class="mt-4 flex gap-2">
                        {{-- Icon-only on a 320px phone, so the longer "On to Pockets" label still fits. --}}
                        <x-ui.button variant="secondary" size="md" icon="arrow_back" x-show="tourIndex > 0" x-on:click="tourBack()" class="max-[360px]:px-3 dark:bg-surface-3" :aria-label="__('Back')" data-test="tour-back"><span class="max-[360px]:sr-only">{{ __('Back') }}</span></x-ui.button>
                        <x-ui.button variant="tonal" size="md" class="min-w-0 flex-1" x-on:click="tourForward()" data-test="tour-next"><span class="truncate" x-text="tourNextLabel">{{ __('Next') }}</span></x-ui.button>
                    </div>
                    <p class="sr-only" aria-live="polite" x-text="tourAnnouncement"></p>
                </div>
            </div>
        </div>
    </template>
</div>
