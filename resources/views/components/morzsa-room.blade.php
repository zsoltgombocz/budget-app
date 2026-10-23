@props([
    'stage' => 'doboz',
    'time' => 'este',
    'jars' => [],
    'mood' => null,
    'items' => [],
])

@php
    /*
     * Morzsa's room (the design's Szoba): a cardboard box at first, a furnished nook later; morning,
     * evening or night; one jar per goal (up to three) filled as far as the goal is; the things bought
     * with acorns (lamp, plant, bookshelf, and what Morzsa wears). Fills its container's width at 16:10.
     */
    $box = $stage === 'doboz';
    $sky = match ($time) { 'reggel' => '#BFE3F2', 'este' => '#7A5A86', default => '#1C2340' };
    $sky2 = match ($time) { 'reggel' => '#D7EEF6', 'este' => '#E8955A', default => '#1C2340' };
    $sunX = $box ? 112 : 116;
    $jarPos = $box ? [[232, 172], [258, 176], [206, 176]] : [[246, 96], [270, 96], [294, 96]];
    $jars = array_slice(array_values($jars), 0, 3);
    $has = fn (string $item): bool => in_array($item, $items, true);
    $mood ??= $time === 'ejjel' ? 'alvo' : 'nyugodt';
    $labels = ['doboz' => __('a cardboard-box nook'), 'kucko' => __('a furnished nook')];
    $times = ['reggel' => __('in the morning'), 'este' => __('in the evening, by lamplight'), 'ejjel' => __('at night, with stars')];
    $alt = __('Morzsa’s room: :room, :time.', ['room' => $labels[$box ? 'doboz' : 'kucko'], 'time' => $times[$time] ?? '']);
@endphp

<div role="img" aria-label="{{ $alt }}" {{ $attributes->class(['relative aspect-[16/10] w-full overflow-hidden rounded-card']) }}>
    <svg viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" class="absolute inset-0 block size-full">
        @if ($box)
            <rect x="0" y="0" width="320" height="200" fill="#8F6232" />
            <rect x="40" y="20" width="240" height="130" fill="#C8955B" />
            <polygon points="0,0 40,20 40,150 0,200" fill="#A9773F" />
            <polygon points="320,0 280,20 280,150 320,200" fill="#9E6E3A" />
            <polygon points="0,0 320,0 280,20 40,20" fill="#7E5629" />
            <polygon points="40,150 280,150 320,200 0,200" fill="#B5824A" />
            <rect x="186" y="20" width="18" height="130" fill="#D9AE78" opacity="0.55" />
            <rect x="62" y="40" width="72" height="54" rx="12" fill="{{ $sky }}" />
            <rect x="62" y="67" width="72" height="27" fill="{{ $sky2 }}" />
            <rect x="62" y="40" width="72" height="54" rx="12" fill="none" stroke="#8F6232" stroke-width="3" />
        @else
            <rect x="0" y="0" width="320" height="200" fill="#4A3E35" />
            <rect x="0" y="118" width="320" height="34" fill="#54473C" />
            <rect x="0" y="150" width="320" height="50" fill="#6B4F38" />
            <path d="M0 166 H320 M0 183 H320 M70 150 V166 M190 150 V166 M130 166 V183 M250 166 V183 M40 183 V200 M210 183 V200" fill="none" stroke="#5A412D" stroke-width="1.5" />
            <rect x="54" y="28" width="80" height="64" fill="{{ $sky }}" />
            <rect x="54" y="60" width="80" height="32" fill="{{ $sky2 }}" />
            <rect x="54" y="28" width="80" height="64" fill="none" stroke="#E9DCC6" stroke-width="4" />
            <path d="M94 28 V92 M54 60 H134" fill="none" stroke="#E9DCC6" stroke-width="3" />
            <path d="M44 22 Q54 60 48 100 L36 100 Q40 60 34 22 Z" fill="#E9DCC6" />
            <path d="M144 22 Q134 60 140 100 L152 100 Q148 60 154 22 Z" fill="#E9DCC6" />
            <rect x="30" y="18" width="128" height="5" rx="2" fill="#8A5A34" />
            <rect x="50" y="92" width="88" height="6" rx="2" fill="#E9DCC6" />
            <rect x="198" y="30" width="46" height="36" rx="3" fill="#E9DCC6" />
            <rect x="203" y="35" width="36" height="26" fill="#9CC9B4" />
            <path d="M203 61 Q214 47 224 55 Q231 49 239 56 V61 Z" fill="#3E8E63" />
            <circle cx="232" cy="41" r="3.5" fill="#FFE7A0" />
            @if ($has('shelf'))
                <rect x="212" y="96" width="92" height="6" rx="2" fill="#8A5A34" />
                <rect x="216" y="74" width="8" height="22" fill="#9C86E8" />
                <rect x="225" y="78" width="7" height="18" fill="#C98B5B" />
                <rect x="233" y="72" width="9" height="24" fill="#6E8F7A" />
            @endif
            @if ($has('plant'))
                <path d="M262 140 h20 l-3 14 h-14 z" fill="#B8763A" />
                <path d="M272 140 Q262 120 250 116 Q264 114 272 132 Q276 108 290 100 Q282 118 276 138 Z" fill="#3DB874" />
                <path d="M272 138 Q286 124 298 124 Q288 132 276 140 Z" fill="#2B9159" />
            @endif
            <ellipse cx="160" cy="180" rx="118" ry="15" fill="#9C86E8" opacity="0.55" />
        @endif
        @if ($time === 'reggel')
            <circle cx="{{ $sunX }}" cy="56" r="9" fill="#FFE7A0" />
        @elseif ($time === 'ejjel')
            <circle cx="{{ $sunX }}" cy="52" r="8" fill="#FFF1DC" />
            <circle cx="{{ $sunX + 4 }}" cy="49" r="7" fill="#1C2340" />
            <circle cx="{{ $sunX - 34 }}" cy="44" r="1.2" fill="#FFF1DC" />
            <circle cx="{{ $sunX - 12 }}" cy="58" r="1" fill="#FFF1DC" />
            <circle cx="{{ $sunX + 14 }}" cy="70" r="1.2" fill="#FFF1DC" />
        @endif
        <rect x="0" y="0" width="320" height="200" fill="#0A0C1E" opacity="{{ match ($time) { 'ejjel' => 0.28, 'este' => 0.08, default => 0 } }}" />
        @if ($has('lamp'))
            @php [$lampX, $lampY] = $box ? [86, 124] : [256, 98]; @endphp
            @if ($time !== 'reggel')
                <circle cx="{{ $lampX }}" cy="{{ $lampY - 4 }}" r="70" fill="#FFD696" opacity="0.13" />
                <circle cx="{{ $lampX }}" cy="{{ $lampY - 4 }}" r="38" fill="#FFD696" opacity="0.14" />
            @endif
            <g transform="translate({{ $lampX }} {{ $lampY }})">
                <rect x="-2" y="0" width="4" height="56" fill="#2A1D15" />
                <ellipse cx="0" cy="56" rx="12" ry="3.5" fill="#2A1D15" />
                <path d="M-16 0 L16 0 L10 -20 L-10 -20 Z" fill="{{ $time === 'reggel' ? '#E9DCC6' : '#FFD696' }}" />
            </g>
        @endif
        <ellipse cx="160" cy="178" rx="56" ry="12" fill="#7E64D6" />
        <ellipse cx="160" cy="174" rx="50" ry="9" fill="#9C86E8" />
        @foreach ($jars as $index => $fill)
            @php $height = (int) round(24 * max(0.06, min(1, (float) $fill))); @endphp
            <g transform="translate({{ $jarPos[$index][0] }} {{ $jarPos[$index][1] }})">
                <rect x="-1" y="-33" width="22" height="5" rx="2" fill="#8A5A34" />
                <rect x="0" y="-28" width="20" height="28" rx="4" fill="rgba(255,241,220,.18)" />
                <rect x="2" y="{{ -2 - $height }}" width="16" height="{{ $height }}" rx="2" fill="#E9C38F" />
                <rect x="0" y="-28" width="20" height="28" rx="4" fill="none" stroke="#FFF1DC" stroke-opacity="0.75" stroke-width="1.5" />
            </g>
        @endforeach
    </svg>
    <div class="absolute bottom-[6%] left-1/2 aspect-square w-[34%] -translate-x-1/2">
        <x-morzsa :mood="$mood" size="100%" :hat="$has('hat')" :scarf="$has('scarf')" :glasses="$has('glasses')" :pouch="$jars !== []" />
    </div>
</div>
