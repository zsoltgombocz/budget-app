<?php

use App\Livewire\Pulse\SecurityEvents as SecurityEventsCard;
use App\Models\Admin;
use App\Models\User;
use App\Support\SecurityEvents;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

it('records a wrong sign-in code and shows it on the monitoring card', function (): void {
    Pulse::startRecording();
    $user = User::factory()->create();

    Livewire::test('auth.magic-link-form')
        ->set('email', $user->email)
        ->call('send')
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    Pulse::ingest();

    expect(DB::table('pulse_entries')->where('type', SecurityEvents::TYPE)->count())->toBe(1);

    $this->actingAs(Admin::factory()->create(), 'admin');
    Livewire::test(SecurityEventsCard::class, ['lazy' => false])
        ->assertSee(__('Wrong sign-in code'))
        ->assertSee('127.0.0.1');
});
