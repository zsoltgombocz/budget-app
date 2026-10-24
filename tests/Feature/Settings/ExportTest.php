<?php

use App\Actions\Account\ExportUserData;
use App\Models\Transaction;
use App\Services\PeriodService;

it('downloads everything stored about the user as CSV files, without secrets or other users', function (): void {
    $user = onboardedUser();
    $other = onboardedUser();
    $period = resolve(PeriodService::class)->current($user);
    Transaction::factory()->for($user)->for($period)->create(['amount' => 4_321, 'note' => 'Pék']);
    Transaction::factory()->for($other)->for(resolve(PeriodService::class)->current($other))->create(['note' => 'Someone else']);

    $tables = resolve(ExportUserData::class)->tables($user);

    expect($tables)->toHaveKeys(['users', 'transactions', 'categories', 'budget_lines', 'periods', 'budget_settings'])
        ->and($tables['users'][0])->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret'])
        ->and(array_column($tables['transactions'], 'note'))->toBe(['Pék']);

    $this->actingAs($user)->get(route('export'))
        ->assertOk()
        ->assertDownload('moneysight-adatok-'.now()->format('Y-m-d').'.zip');
});
