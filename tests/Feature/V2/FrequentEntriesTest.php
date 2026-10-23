<?php

use App\Models\Category;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FrequentEntries;
use Carbon\CarbonImmutable;

it('turns an amount recorded three times in 30 days into a chip, most used first', function (): void {
    $user = User::factory()->create();
    $period = Period::factory()->for($user)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    [$coffee, $shop] = Category::factory()->for($user)->count(2)->create();
    $record = fn (Category $category, int $amount, string $date) => Transaction::factory()->for($user)->for($period)->for($category)->create(['amount' => $amount, 'occurred_on' => $date]);

    foreach (['2026-10-10', '2026-10-12', '2026-10-14', '2026-10-15'] as $date) {
        $record($coffee, 890, $date);
    }
    foreach (['2026-10-11', '2026-10-13', '2026-10-15'] as $date) {
        $record($shop, 4_500, $date);
    }
    $record($shop, 1_200, '2026-10-15');
    $record($shop, 1_200, '2026-10-14');
    // Too long ago to count.
    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        $record($shop, 9_999, $date);
    }

    expect(new FrequentEntries()->forUser($user, CarbonImmutable::parse('2026-10-15')))->toBe([
        ['category_id' => $coffee->id, 'amount' => 890, 'count' => 4],
        ['category_id' => $shop->id, 'amount' => 4_500, 'count' => 3],
    ]);
});
