<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['services.bug_report.dsn' => 'https://publickey@o1.ingest.de.sentry.io/42']);
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

it('is linked from the settings menu', function (): void {
    $this->get(route('settings'))->assertOk()->assertSee(route('bug-report'));
    $this->get(route('bug-report'))->assertOk()->assertSee('data-test="bug-report-form"', false);
});

it('sends a bug report to Sentry and thanks the user', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    Livewire::test('pages::settings.bug-report')
        ->set('message', 'The Next button does nothing')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee('data-test="bug-report-sent"', false);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'The Next button does nothing'));
});

it('needs a description', function (): void {
    Http::fake();

    Livewire::test('pages::settings.bug-report')->set('message', '')->call('send')->assertHasErrors('message');

    Http::assertNothingSent();
});

it('says so when the report cannot be sent', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response('', 500)]);

    Livewire::test('pages::settings.bug-report')
        ->set('message', 'The Next button does nothing')
        ->call('send')
        ->assertHasErrors('message')
        ->assertSet('sent', false);
});

it('limits a user to five reports an hour', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    foreach (range(1, 5) as $attempt) {
        Livewire::test('pages::settings.bug-report')->set('message', 'Report number '.$attempt)->call('send')->assertHasNoErrors();
    }

    Livewire::test('pages::settings.bug-report')->set('message', 'One too many')->call('send')->assertHasErrors('message');

    Http::assertSentCount(5);
});
