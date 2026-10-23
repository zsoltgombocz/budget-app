@props([
    'mood' => 'nyugodt',
    'size' => 160,
    'pouch' => false,
    'hat' => false,
    'scarf' => false,
    'glasses' => false,
])

@php
    /*
     * Morzsa, the hamster, as a still SVG in one of ten moods (the design's character sheet).
     * The rim light shows in the dark theme, the outline in the light one; both are drawn and CSS picks.
     */
    $moods = ['nyugodt', 'udvozlo', 'vidam', 'kivancsi', 'elgondolkodo', 'unneplo', 'alvo', 'buszke', 'meglepett', 'szamolos'];
    $m = in_array($mood, $moods, true) ? $mood : 'nyugodt';
    $alts = [
        'nyugodt' => __('Morzsa sits calmly.'),
        'udvozlo' => __('Morzsa waves.'),
        'vidam' => __('Morzsa is happy.'),
        'kivancsi' => __('Morzsa looks curious.'),
        'elgondolkodo' => __('Morzsa is thinking.'),
        'unneplo' => __('Morzsa celebrates.'),
        'alvo' => __('Morzsa is asleep.'),
        'buszke' => __('Morzsa is proud.'),
        'meglepett' => __('Morzsa is surprised.'),
        'szamolos' => __('Morzsa is counting.'),
    ];
    $sleep = $m === 'alvo';
    $open = in_array($m, ['udvozlo', 'kivancsi', 'elgondolkodo', 'meglepett', 'szamolos'], true);
    $er = match ($m) { 'meglepett' => 6.2, 'kivancsi' => 5.6, default => 5 };
    $dx = $m === 'elgondolkodo' ? 1.8 : 0;
    $dy = match ($m) { 'szamolos' => 1.6, 'elgondolkodo' => -0.8, default => 0 };
    $full = (bool) $pouch;
    $earL = match ($m) { 'buszke' => 'rotate(-22 38 20)', 'meglepett' => 'translate(0 -2)', default => 'rotate(0)' };
    $earR = match ($m) { 'kivancsi' => 'rotate(38 62 20)', 'buszke' => 'rotate(22 62 20)', 'meglepett' => 'translate(0 -2)', default => 'rotate(0)' };
    $tilt = match ($m) { 'kivancsi' => 'rotate(-8 50 60)', 'meglepett' => 'translate(0 -3)', default => 'rotate(0)' };
    $chRx = $full ? 11.2 : 10;
    $chL = $full ? 25.8 : 27;
    $chR = $full ? 74.2 : 73;
    $blL = $full ? 24.8 : 26;
    $blR = $full ? 75.2 : 74;
    $hl1 = 36 - $er * 0.36;
    $hl2 = 64 - $er * 0.36;
    $hly = 38 - $er * 0.36;
    $is = fn (string ...$names): bool => in_array($m, $names, true);
@endphp

<div role="img" aria-label="{{ $alts[$m] }}" {{ $attributes->class(['morzsa relative block shrink-0']) }} style="width: {{ $size }}px; height: {{ $size }}px">
    <svg viewBox="0 0 100 100" aria-hidden="true" class="block size-full overflow-visible">
        <ellipse cx="50" cy="95" rx="{{ $sleep ? 42 : 35 }}" ry="4" class="morzsa-shadow"></ellipse>
        @unless ($sleep)
            <ellipse cx="35" cy="91.5" rx="7" ry="4" fill="#B8763A"></ellipse>
            <ellipse cx="65" cy="91.5" rx="7" ry="4" fill="#B8763A"></ellipse>
            <g transform="{{ $tilt }}">
                <g transform="{{ $earL }}"><circle cx="35" cy="14" r="8" fill="#E3A462"></circle><circle cx="35" cy="15" r="4.6" fill="#F2A39B"></circle></g>
                <g transform="{{ $earR }}"><circle cx="65" cy="14" r="8" fill="#E3A462"></circle><circle cx="65" cy="15" r="4.6" fill="#F2A39B"></circle></g>
                @if ($is('unneplo'))
                    <ellipse cx="13" cy="34" rx="5.6" ry="10" transform="rotate(-32 13 34)" fill="#E3A462"></ellipse>
                    <ellipse cx="87" cy="34" rx="5.6" ry="10" transform="rotate(32 87 34)" fill="#E3A462"></ellipse>
                @endif
                @if ($is('udvozlo'))
                    <ellipse cx="87" cy="38" rx="5.6" ry="10" transform="rotate(30 87 38)" fill="#E3A462"></ellipse>
                    <path d="M95 22 Q99 27 98 33 M91 17 Q97 19 100 24" fill="none" stroke="#9AA19D" stroke-width="1.6" stroke-linecap="round"></path>
                @endif
                <path d="M50 9 C75 9 89 27 92 49 C95 72 84 91 50 91 C16 91 5 72 8 49 C11 27 25 9 50 9 Z" fill="#E3A462"></path>
                <path d="M92 49 C95 72 84 91 50 91 C67 88 81 75 84 55 C86 42 83 30 77 21 C86 29 90 38 92 49 Z" fill="#B8763A" opacity="0.5"></path>
                <path d="M30 13 Q50 4.5 70 13" class="morzsa-rim" fill="none" stroke="#FFE3B8" stroke-opacity="0.45" stroke-width="1.5" stroke-linecap="round"></path>
                <path d="M8 49 C5 72 16 91 50 91 C84 91 95 72 92 49" class="morzsa-outline" fill="none" stroke="#B8763A" stroke-width="2"></path>
                <ellipse cx="50" cy="72" rx="27" ry="19" fill="#FFF1DC"></ellipse>
                <ellipse cx="{{ $chL }}" cy="51" rx="{{ $chRx }}" ry="8.5" fill="#FFF1DC"></ellipse>
                <ellipse cx="{{ $chR }}" cy="51" rx="{{ $chRx }}" ry="8.5" fill="#FFF1DC"></ellipse>
                <ellipse cx="50" cy="48.5" rx="9" ry="6.5" fill="#FFF1DC"></ellipse>
                <ellipse cx="{{ $blL }}" cy="53.5" rx="4.6" ry="2.6" fill="#F2A39B" opacity="0.6"></ellipse>
                <ellipse cx="{{ $blR }}" cy="53.5" rx="4.6" ry="2.6" fill="#F2A39B" opacity="0.6"></ellipse>
                @if ($scarf)
                    <path d="M15 57 Q50 69 85 57 L86 64 Q50 77 14 64 Z" fill="#9C86E8"></path>
                    <path d="M28 61.6 L29 68.6 M42 64.4 L42.5 72 M58 64.4 L57.5 72 M72 61.6 L71 68.6" fill="none" stroke="#FFF1DC" stroke-width="2.2" stroke-linecap="round"></path>
                    <path d="M64 66 L73 64 L76 83 L67 85 Z" fill="#8B74DD"></path>
                    <path d="M68 72.5 L75 71 M69 78 L76 76.6" fill="none" stroke="#FFF1DC" stroke-width="2" stroke-linecap="round"></path>
                @endif
                @if ($open)
                    <g transform="translate({{ $dx }} {{ $dy }})">
                        <circle cx="36" cy="38" r="{{ $er }}" fill="#2A1D15"></circle>
                        <circle cx="64" cy="38" r="{{ $er }}" fill="#2A1D15"></circle>
                        <circle cx="{{ $hl1 }}" cy="{{ $hly }}" r="1.5" fill="#FFFFFF"></circle>
                        <circle cx="{{ $hl2 }}" cy="{{ $hly }}" r="1.5" fill="#FFFFFF"></circle>
                    </g>
                @endif
                @if ($is('nyugodt'))
                    <path d="M31 37.5 A5 5 0 0 0 41 37.5 Z M59 37.5 A5 5 0 0 0 69 37.5 Z" fill="#2A1D15"></path>
                    <path class="morzsa-line" d="M30 37.5 H42 M58 37.5 H70"></path>
                @endif
                @if ($is('vidam', 'unneplo'))
                    <path class="morzsa-line" d="M31 40 Q36 33 41 40 M59 40 Q64 33 69 40" stroke-width="2.6"></path>
                @endif
                @if ($is('buszke'))
                    <path class="morzsa-line" d="M31 36.5 Q36 41.5 41 36.5 M59 36.5 Q64 41.5 69 36.5" stroke-width="2.6"></path>
                @endif
                @if ($is('elgondolkodo'))
                    <path class="morzsa-line" d="M30.5 29.5 L40 31.6 M60 31.6 L69.5 29.5"></path>
                @endif
                <path d="M47.2 44.6 Q50 43.4 52.8 44.6 Q51.6 47.8 50 48 Q48.4 47.8 47.2 44.6 Z" fill="#E07F84"></path>
                @if ($is('nyugodt', 'udvozlo', 'buszke'))
                    <path class="morzsa-line" d="M45.5 50.5 Q47.8 53 50 50.8 Q52.2 53 54.5 50.5" stroke-width="2.1"></path>
                @endif
                @if ($is('elgondolkodo'))
                    <path class="morzsa-line" d="M46.5 51.5 H53.5" stroke-width="2.1"></path>
                @endif
                @if ($is('kivancsi', 'szamolos'))
                    <path class="morzsa-line" d="M47.5 51.2 Q50 53.2 52.5 51.2" stroke-width="2.1"></path>
                @endif
                @if ($is('vidam', 'unneplo'))
                    <path d="M45 50.5 Q50 59.5 55 50.5 Z" fill="#2A1D15"></path>
                    <ellipse cx="50" cy="54.8" rx="2.6" ry="1.4" fill="#E07F84"></ellipse>
                @endif
                @if ($is('meglepett'))
                    <ellipse cx="50" cy="52.3" rx="2.1" ry="2.6" fill="#2A1D15"></ellipse>
                @endif
                @unless ($is('unneplo'))
                    <ellipse cx="42" cy="57" rx="5.6" ry="4" fill="#E3A462" stroke="#B8763A" stroke-width="1.2"></ellipse>
                @endunless
                @unless ($is('unneplo', 'udvozlo', 'elgondolkodo', 'buszke'))
                    <ellipse cx="58" cy="57" rx="5.6" ry="4" fill="#E3A462" stroke="#B8763A" stroke-width="1.2"></ellipse>
                @endunless
                @if ($is('elgondolkodo'))
                    <ellipse cx="58" cy="55.5" rx="6" ry="4.4" transform="rotate(-22 58 55.5)" fill="#E3A462" stroke="#B8763A" stroke-width="1.2"></ellipse>
                @endif
                @if ($is('buszke'))
                    <ellipse cx="57" cy="62" rx="6.2" ry="4.5" transform="rotate(-12 57 62)" fill="#E3A462" stroke="#B8763A" stroke-width="1.2"></ellipse>
                @endif
                @if ($is('szamolos'))
                    <path d="M38.5 59 h7 c0 3.6 -1.8 6 -3.5 6.3 c-1.7 -.3 -3.5 -2.7 -3.5 -6.3 z" fill="#E9C38F"></path>
                    <path d="M37.6 59.2 c0 -2.4 2 -3.6 4.4 -3.6 s4.4 1.2 4.4 3.6 z" fill="#8A5A34"></path>
                    <path d="M54.5 59 h7 c0 3.6 -1.8 6 -3.5 6.3 c-1.7 -.3 -3.5 -2.7 -3.5 -6.3 z" fill="#E9C38F"></path>
                    <path d="M53.6 59.2 c0 -2.4 2 -3.6 4.4 -3.6 s4.4 1.2 4.4 3.6 z" fill="#8A5A34"></path>
                @endif
                @if ($glasses)
                    <circle cx="36" cy="38" r="8.2" fill="rgba(255,255,255,.14)" stroke="#2A1D15" stroke-width="2"></circle>
                    <circle cx="64" cy="38" r="8.2" fill="rgba(255,255,255,.14)" stroke="#2A1D15" stroke-width="2"></circle>
                    <path d="M44.2 37 Q50 33.6 55.8 37" fill="none" stroke="#2A1D15" stroke-width="2"></path>
                @endif
                @if ($hat)
                    <path d="M23 27 C22 5 78 5 77 27 Z" fill="#3DB874"></path>
                    <path d="M37 11 V25 M50 8 V25 M63 11 V25" fill="none" stroke="#2B9159" stroke-width="1.6" stroke-linecap="round"></path>
                    <path d="M20.5 25 Q50 17 79.5 25 L79.5 31.5 Q50 23.5 20.5 31.5 Z" fill="#2B9159"></path>
                    <circle cx="50" cy="5.5" r="5" fill="#FFF1DC"></circle>
                    <g transform="{{ $earL }}"><circle cx="35" cy="14" r="8" fill="#E3A462"></circle><circle cx="35" cy="15" r="4.6" fill="#F2A39B"></circle></g>
                    <g transform="{{ $earR }}"><circle cx="65" cy="14" r="8" fill="#E3A462"></circle><circle cx="65" cy="15" r="4.6" fill="#F2A39B"></circle></g>
                @endif
            </g>
            @if ($is('unneplo'))
                <g>
                    <path d="M4 20 h5 c0 2.6 -1.3 4.3 -2.5 4.5 c-1.2 -.2 -2.5 -1.9 -2.5 -4.5 z" fill="#E9C38F"></path>
                    <path d="M3.4 20.2 c0 -1.7 1.4 -2.6 3.1 -2.6 s3.1 .9 3.1 2.6 z" fill="#8A5A34"></path>
                    <path d="M90 8 h5 c0 2.6 -1.3 4.3 -2.5 4.5 c-1.2 -.2 -2.5 -1.9 -2.5 -4.5 z" fill="#E9C38F"></path>
                    <path d="M89.4 8.2 c0 -1.7 1.4 -2.6 3.1 -2.6 s3.1 .9 3.1 2.6 z" fill="#8A5A34"></path>
                    <ellipse cx="22" cy="3" rx="2" ry="4" transform="rotate(40 22 3)" fill="#4BD88A"></ellipse>
                    <ellipse cx="80" cy="-2" rx="2" ry="4" transform="rotate(-30 80 -2)" fill="#4BD88A"></ellipse>
                    <ellipse cx="97" cy="46" rx="2" ry="4" transform="rotate(60 97 46)" fill="#E3A462"></ellipse>
                    <circle cx="0" cy="44" r="2" fill="#B9A3FF"></circle>
                    <circle cx="36" cy="-4" r="1.8" fill="#B9A3FF"></circle>
                    <circle cx="66" cy="-6" r="1.6" fill="#FFF1DC"></circle>
                    <circle cx="100" cy="28" r="1.8" fill="#B9A3FF"></circle>
                    <circle cx="10" cy="6" r="1.5" fill="#FFF1DC"></circle>
                </g>
            @endif
        @else
            <circle cx="30" cy="51" r="6.5" fill="#E3A462"></circle><circle cx="30" cy="52" r="3.8" fill="#F2A39B"></circle>
            <circle cx="46" cy="47" r="6.5" fill="#E3A462"></circle><circle cx="46" cy="48" r="3.8" fill="#F2A39B"></circle>
            <path d="M10 76 C10 56 28 46 50 46 C74 46 92 58 92 76 C92 88 78 92 50 92 C22 92 10 88 10 76 Z" fill="#E3A462"></path>
            <path d="M10 76 C10 88 22 92 50 92 C78 92 92 88 92 76 C84 84 70 86.5 50 86.5 C30 86.5 16 84 10 76 Z" fill="#B8763A" opacity="0.5"></path>
            <path d="M28 50 Q48 42 76 52" class="morzsa-rim" fill="none" stroke="#FFE3B8" stroke-opacity="0.45" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M10 76 C10 88 22 92 50 92 C78 92 92 88 92 76" class="morzsa-outline" fill="none" stroke="#B8763A" stroke-width="2"></path>
            <ellipse cx="30" cy="75" rx="12" ry="8" fill="#FFF1DC"></ellipse>
            <ellipse cx="22" cy="74" rx="4" ry="2.2" fill="#F2A39B" opacity="0.6"></ellipse>
            <path class="morzsa-line" d="M21.5 67 Q25 70 28.5 67 M35.5 65.5 Q39 68.5 42.5 65.5"></path>
            <path d="M30.4 71.6 Q32.6 70.6 34.8 71.6 Q33.8 74.2 32.6 74.4 Q31.4 74.2 30.4 71.6 Z" fill="#E07F84"></path>
            <ellipse cx="44" cy="85" rx="6.5" ry="3.6" fill="#FFF1DC" stroke="#B8763A" stroke-width="1.2"></ellipse>
            <path d="M22 55 Q28 38 46 46 Q58 31 76 33 Q68 40 57 51 Z" fill="#9C86E8"></path>
            <path d="M21 56 Q35 46 57 51" fill="none" stroke="#FFF1DC" stroke-width="3" stroke-linecap="round"></path>
            <circle cx="77" cy="33" r="3.8" fill="#FFF1DC"></circle>
            <path d="M70 15 h7 l-7 8 h7 M83 4 h5 l-5 6 h5" fill="none" stroke="#9AA19D" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        @endunless
    </svg>
</div>
