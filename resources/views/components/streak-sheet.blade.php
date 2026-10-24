@props(['streak', 'activeDates', 'today', 'challenge', 'milestones'])

{{-- The streak, this week, the weekly challenge and the milestones; opened from the flame on Today. --}}
@php
    $active = array_flip($activeDates);
    $weekStart = $today->startOfWeek();
    $dayNames = [__('Mo'), __('Tu'), __('We'), __('Th'), __('Fr'), __('Sa'), __('Su')];
    $reached = count(array_filter($milestones, fn ($m) => $m->achievedAt !== null));
    $writtenToday = isset($active[$today->toDateString()]);
@endphp

<x-ui.sheet name="streak" :title="null">
    <div class="pt-4 pb-2" data-test="streak-sheet">
        <div class="flex items-center gap-4">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-warn-tint text-warn">
                <x-ui.icon name="local_fire_department" :size="36" :fill="true" />
            </span>
            <div>
                <h2 class="num text-[28px] font-bold tracking-[-0.02em]">{{ trans_choice('{0} No streak yet|{1} :count-day streak|[2,*] :count-day streak', $streak->current, ['count' => $streak->current]) }}</h2>
                <div class="num text-sm text-muted">{{ __('Longest: :count days', ['count' => $streak->longest]) }}</div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-7">
            @foreach ($dayNames as $index => $name)
                @php
                    $day = $weekStart->addDays($index);
                    $done = isset($active[$day->toDateString()]);
                    $isToday = $day->isSameDay($today);
                @endphp
                <div class="flex flex-col items-center gap-1.5 text-xs text-muted">
                    @if ($done)
                        <span class="flex size-[34px] items-center justify-center rounded-full bg-warn text-[#2B1A05]"><x-ui.icon name="check" :size="18" :weight="600" /></span>
                    @else
                        <span @class(['size-[34px] rounded-full', 'border-2 border-dashed border-warn' => $isToday, 'bg-surface-2' => ! $isToday])></span>
                    @endif
                    <span @class(['font-semibold text-ink' => $isToday])>{{ $name }}</span>
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-center text-sm text-muted">
            @if ($writtenToday)
                {{ __('Today is done.') }}
            @elseif ($streak->restUsedYesterday)
                {{ __('Yesterday was a rest day, the streak goes on.') }}
            @else
                {{ __('Nothing written in today yet.') }}
            @endif
        </p>

        <div class="mt-5 rounded-[22px] bg-surface-2 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-[13px] text-muted">{{ $challenge->completed ? __('Weekly challenge done') : __('Weekly challenge') }}</div>
                    <div class="text-base font-semibold">{{ $challenge->kind === 'no_spend' ? trans_choice('{1} :count no-spend day|[2,*] :count no-spend days', $challenge->target, ['count' => $challenge->target]) : trans_choice('{1} Write in on :count day|[2,*] Write in on :count days', $challenge->target, ['count' => $challenge->target]) }}</div>
                </div>
                <span class="num text-base font-bold">{{ $challenge->done }} / {{ $challenge->target }}</span>
            </div>
            <div class="mt-3 flex gap-1.5">
                @for ($i = 0; $i < $challenge->target; $i++)
                    <span @class(['h-2 flex-1 rounded-full', 'bg-accent' => $i < $challenge->done, 'bg-surface-3' => $i >= $challenge->done])></span>
                @endfor
            </div>
            <div class="mt-2.5 text-[13px] text-muted">{{ __('Until Sunday evening') }}</div>
        </div>

        <h3 class="mx-1 mt-6 mb-2.5 text-[17px] font-semibold">{{ __('Milestones') }} <span class="num font-normal text-muted">{{ $reached }} / {{ count($milestones) }}</span></h3>
        <div class="grid grid-cols-3 gap-2.5">
            @foreach ($milestones as $milestone)
                <div class="flex min-h-28 flex-col items-center gap-2 rounded-[18px] bg-surface-2 px-2.5 pt-3.5 pb-3 text-center" data-test="milestone-{{ $milestone->key }}">
                    <span @class(['flex size-11 items-center justify-center rounded-full', 'bg-warn text-[#2B1A05]' => $milestone->achievedAt, 'bg-surface-3 text-faint' => ! $milestone->achievedAt])>
                        <x-ui.icon :name="$milestone->icon" :size="22" :fill="(bool) $milestone->achievedAt" />
                    </span>
                    <span class="text-[13px] leading-tight font-semibold">{{ $milestone->title }}</span>
                    <span class="num text-xs text-muted">{{ $milestone->achievedAt ? \App\Support\Dates::short($milestone->achievedAt) : ($milestone->detail ?? '') }}</span>
                </div>
            @endforeach
        </div>

        <x-ui.button variant="secondary" class="mt-6 w-full" x-on:click="$dispatch('close-sheet', 'streak')">{{ __('Done') }}</x-ui.button>
    </div>
</x-ui.sheet>
