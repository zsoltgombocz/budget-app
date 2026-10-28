<?php

use App\Actions\Account\ExportUserData;
use App\Models\Transaction;
use App\Models\User;
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

it('removes sign-ups that never confirmed their address after a week, but not invited ones', function (): void {
    $stale = User::factory()->create(['email_verified_at' => null, 'created_at' => now()->subDays(8)]);
    $invited = User::factory()->create(['email_verified_at' => null, 'invited_at' => now()->subDays(8), 'created_at' => now()->subDays(8)]);
    $fresh = User::factory()->create(['email_verified_at' => null]);

    $this->artisan('model:prune', ['--model' => [User::class]])->assertSuccessful();

    expect(User::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($invited->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('shows the legal pages without signing in', function (): void {
    foreach (['aszf', 'adatvedelem', 'impresszum', 'sutik'] as $page) {
        if (is_file(resource_path("legal/{$page}.md"))) {
            $this->get(route('legal', $page))->assertOk()->assertSee('data-test="legal-'.$page.'"', false)->assertDontSee('{{operator_email}}', false);
        }
    }

    $this->get('/register-nope')->assertNotFound();
});

it('leads back from the legal pages to where the reader came from, also after switching documents', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('legal', 'aszf'), ['referer' => route('subscription')])
        ->assertSee('href="'.route('subscription').'"', false);

    $this->actingAs($user)->get(route('legal', 'impresszum'), ['referer' => route('legal', 'aszf')])
        ->assertSee('href="'.route('subscription').'"', false);
});

it('leads back from the legal pages to settings or the landing page when the origin is unknown', function (): void {
    $this->get(route('legal', 'aszf'), ['referer' => 'https://example.com/'])->assertSee('href="'.url('/').'"', false);

    $this->actingAs(User::factory()->create())->get(route('legal', 'aszf'))->assertSee('href="'.route('settings').'"', false);
});
