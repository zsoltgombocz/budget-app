{{--
    "Into pockets" on the closing summary and the closed screen: every pocket the closing moves
    money in, its parts (the plan's monthly saving, the leftover share, a deficit taken from it)
    and the total with the new balance. The undo dialog takes back exactly these totals.

    @var list<array{pocket_id: int|null, name: string, monthly: int, leftover: int, cover: int, total: int, balance_after: int}> $moves
--}}
@if ($moves !== [])
    <section @class([$class ?? 'mx-4 mb-3 mt-3']) data-test="pocket-moves">
        <x-ui.section-label :label="__('Into pockets')" />
        <div class="num rounded-card bg-surface px-[18px]">
            @foreach ($moves as $move)
                @php
                    $parts = array_values(array_filter([
                        [__('monthly saving'), $move['monthly']],
                        [__('from the leftover'), $move['leftover']],
                        [__('deficit covered'), $move['cover']],
                    ], fn (array $part): bool => $part[1] !== 0));
                    $signed = fn (int $amount): string => ($amount > 0 ? '+' : '').money($amount);
                @endphp
                {{-- One grid per pocket: every label on the left, every amount in one right-aligned column. --}}
                <div @class(['grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-0.5 py-3 text-xs text-muted', 'border-b border-line' => ! $loop->last]) wire:key="move-{{ $move['pocket_id'] ?? 'new' }}" data-test="pocket-move">
                    <span class="min-w-0 break-words text-[15px] font-medium text-ink">{{ $move['name'] }}</span>
                    <span @class(['text-[15px] font-semibold', 'text-danger' => $move['total'] < 0, 'text-ink' => $move['total'] >= 0])>{{ $signed($move['total']) }}</span>
                    @if (count($parts) > 1)
                        @foreach ($parts as [$label, $amount])
                            <span>{{ $label }}</span><span class="text-right">{{ $signed($amount) }}</span>
                        @endforeach
                    @elseif ($parts !== [])
                        <span class="col-span-2">{{ $parts[0][0] }}</span>
                    @endif
                    <span class="text-ink-2">{{ __('new balance') }}</span><span class="text-right text-ink-2">{{ money($move['balance_after']) }}</span>
                </div>
            @endforeach
        </div>
    </section>
@endif
