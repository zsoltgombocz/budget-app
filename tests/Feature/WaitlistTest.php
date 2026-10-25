<?php

use App\Filament\Resources\WaitlistSignups\Pages\ManageWaitlistSignups;
use App\Models\Admin;
use App\Models\User;
use App\Models\WaitlistSignup;
use App\Notifications\WaitlistConfirmation;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('shows the landing page to guests and sends signed-in users to the app', function (): void {
    $this->get(route('home'))->assertOk()->assertSee('Ennyi marad', false)->assertSee('id="varolista"', false);

    $this->actingAs(onboardedUser())->get(route('home'))->assertRedirect(route('dashboard'));
});

it('puts an address on the waitlist only after it is confirmed, and deletes it on unsubscribe', function (): void {
    Notification::fake();

    $this->post(route('waitlist.store'), ['email' => 'Anna@Example.com'])
        ->assertRedirect(route('home').'#varolista')
        ->assertSessionHas('waitlist', 'sent');

    $signup = WaitlistSignup::query()->sole();
    expect($signup->email)->toBe('anna@example.com')->and($signup->isConfirmed())->toBeFalse();
    Notification::assertSentTo(new AnonymousNotifiable, WaitlistConfirmation::class, fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'anna@example.com');

    $this->get(route('waitlist.confirm', $signup->token))->assertOk()->assertSee('data-test="waitlist-confirmed"', false);
    expect($signup->fresh()->isConfirmed())->toBeTrue();

    $this->get(route('waitlist.unsubscribe', $signup->token))->assertOk()->assertSee('data-test="waitlist-removed"', false);
    expect(WaitlistSignup::query()->count())->toBe(0);
});

it('ignores bots and asks for a real address', function (): void {
    Notification::fake();

    $this->post(route('waitlist.store'), ['email' => 'bot@example.com', 'website' => 'spam'])->assertSessionHasErrors('website');
    $this->post(route('waitlist.store'), ['email' => 'not-an-address'])->assertSessionHasErrors('email');

    expect(WaitlistSignup::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('does not send again to a confirmed address', function (): void {
    Notification::fake();
    WaitlistSignup::factory()->confirmed()->create(['email' => 'bela@example.com']);

    $this->post(route('waitlist.store'), ['email' => 'bela@example.com'])->assertSessionHas('waitlist', 'sent');

    Notification::assertNothingSent();
    expect(User::query()->count())->toBe(0);
});

it('lists the waitlist in the admin panel', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    WaitlistSignup::factory()->confirmed()->create(['email' => 'kati@example.com']);

    Livewire::test(ManageWaitlistSignups::class)
        ->assertCanSeeTableRecords(WaitlistSignup::all())
        ->callAction('export')
        ->assertHasNoActionErrors();
});

it('deletes unconfirmed sign-ups after 30 days', function (): void {
    $old = WaitlistSignup::factory()->create(['created_at' => now()->subDays(31)]);
    $confirmed = WaitlistSignup::factory()->confirmed()->create(['created_at' => now()->subDays(31)]);

    $this->artisan('model:prune', ['--model' => [WaitlistSignup::class]])->assertSuccessful();

    expect(WaitlistSignup::query()->pluck('id')->all())->toBe([$confirmed->id]);
});
