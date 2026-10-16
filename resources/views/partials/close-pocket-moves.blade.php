{{--
    "Into pockets" on the closing summary and the closed screen: every pocket the closing moves
    money in, its parts (the plan's monthly saving, the leftover share, a deficit taken from it)
    and the total with the new balance. The undo dialog takes back exactly these totals.

    @var list<array{pocket_id: int|null, name: string, monthly: int, leftover: int, cover: int, total: int, balance_after: int}> $moves
--}}
@if ($moves !== [])
    <section @class([$class ?? 'mx-4 mb-3 mt-3']) data-test="pocket-moves">
        <x-ui.section-label :label="__('Into pockets')" />
        <div class="num rounded-[22px] bg-surface px-[18px]">
            @foreach ($moves as $move)
                <div @class(['py-3 text-sm', 'border-b border-line' => ! $loop->last]) wire:key="move-{{ $move['pocket_id'] ?? 'new' }}" data-test="pocket-move">
                    <div class="flex items-baseline justify-between gap-3 text-[15px] font-medium">
                        <span class="min-w-0 break-words">{{ $move['name'] }}</span>
                        <span @class(['shrink-0', 'text-danger' => $move['total'] < 0])>{{ $move['total'] > 0 ? '+' : '' }}{{ money($move['total']) }}</span>
                    </div>
                    @php
                        $parts = array_filter([
                            [__('monthly saving'), $move['monthly']],
                            [__('from the leftover'), $move['leftover']],
                            [__('deficit covered'), $move['cover']],
                        ], fn (array $part): bool => $part[1] !== 0);
                    @endphp
                    @if (count($parts) > 1)
                        @foreach ($parts as [$label, $amount])
                            <div class="mt-0.5 flex justify-between gap-3 text-xs text-muted"><span>{{ $label }}</span><span>{{ $amount > 0 ? '+' : '' }}{{ money($amount) }}</span></div>
                        @endforeach
                    @elseif ($parts !== [])
                        <div class="mt-0.5 text-xs text-muted">{{ array_values($parts)[0][0] }}</div>
                    @endif
                    <div class="mt-0.5 text-xs text-ink-2">{{ __('new balance :amount', ['amount' => money($move['balance_after'])]) }}</div>
                </div>
            @endforeach
        </div>
    </section>
@endif
