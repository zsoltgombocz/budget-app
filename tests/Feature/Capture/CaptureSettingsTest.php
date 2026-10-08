<?php

use App\Enums\CaptureStatus;
use App\Models\CaptureToken;
use App\Models\Category;
use App\Models\PaymentCapture;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

it('is linked from the settings menu and shows the setup guide for both phones', function (): void {
    $this->get(route('settings'))->assertSee(route('capture.edit'));

    $this->get(route('capture.edit'))
        ->assertOk()
        ->assertSee('data-test="guide-ios"', false)
        ->assertSee('data-test="guide-android"', false)
        ->assertSee(route('api.captures.store'))
        ->assertSee('Nothing has arrived yet');
});

it('creates a key that is shown once and stored only as a hash', function (): void {
    $component = Livewire::test('pages::settings.capture')->call('createToken', 'ios');

    $plain = $component->get('newToken');
    $token = CaptureToken::query()->sole();

    expect($plain)->toStartWith(CaptureToken::PREFIX)
        ->and($token->name)->toBe('iPhone')
        ->and($token->token_hash)->toBe(CaptureToken::hash($plain))
        ->and($token->token_hash)->not->toBe($plain);

    $component->assertSee($plain);
    Livewire::test('pages::settings.capture')->assertDontSee($plain);
});

it('limits the number of keys', function (): void {
    CaptureToken::factory()->for($this->user)->count(5)->create();

    Livewire::test('pages::settings.capture')->call('createToken', 'android')->assertHasErrors('name');

    expect(CaptureToken::query()->count())->toBe(5);
});

it('deletes a key', function (): void {
    $token = CaptureToken::factory()->for($this->user)->create();

    Livewire::test('pages::settings.capture')->call('deleteToken', $token->id)->assertDispatched('app-toast');

    expect(CaptureToken::query()->count())->toBe(0);
});

it('cannot delete someone else’s key', function (): void {
    $foreign = CaptureToken::factory()->for(User::factory())->create();

    Livewire::test('pages::settings.capture')->call('deleteToken', $foreign->id);

    expect(CaptureToken::query()->withoutGlobalScopes()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('saves the category for new shops', function (): void {
    $groceries = Category::query()->where('name', 'Groceries')->firstOrFail();

    Livewire::test('pages::settings.capture')->set('categoryId', $groceries->id)->assertHasNoErrors();

    expect($this->user->settings()->refresh()->capture_category_id)->toBe($groceries->id);
});

it('only accepts the user’s own variable categories', function (string $which): void {
    $category = $which === 'fixed'
        ? Category::query()->where('name', 'Rent')->firstOrFail()
        : Category::factory()->for(User::factory())->create(['type' => 'variable']);

    Livewire::test('pages::settings.capture')->set('categoryId', $category->id)->assertHasErrors('categoryId');

    expect($this->user->settings()->refresh()->capture_category_id)->toBeNull();
})->with(['fixed', 'other user']);

it('shows what arrived last', function (): void {
    PaymentCapture::factory()->for($this->user)->create(['merchant' => 'Tesco', 'amount' => 8_400, 'status' => CaptureStatus::Recorded]);

    Livewire::test('pages::settings.capture')
        ->assertSee('Last arrival')
        ->assertSee('Tesco')
        ->assertSee(money(8_400));
});
