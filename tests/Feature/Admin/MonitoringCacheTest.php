<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

it('reads back the card data Pulse caches from a serializing store', function (): void {
    config(['cache.stores.pulse-test' => ['driver' => 'file', 'path' => storage_path('framework/testing/pulse-cache')]]);
    $store = Cache::store('pulse-test');

    $store->put('card', [collect([(object) ['key' => 'x', 'count' => 3]]), CarbonImmutable::now()], 60);
    [$rows, $runAt] = $store->get('card');

    expect($rows)->toBeInstanceOf(Collection::class)
        ->and($rows->first()->count)->toBe(3)
        ->and($runAt)->toBeInstanceOf(CarbonImmutable::class);

    $store->flush();
});
