<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['services.bug_report.dsn' => 'https://publickey@o1.ingest.de.sentry.io/42']);
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

it('is reached from Settings through the Contact page', function (): void {
    config(['budget.contact_email' => 'hello@moneysight.app']);

    $this->get(route('settings'))->assertOk()->assertSee(route('contact'));
    $this->get(route('contact'))->assertOk()
        ->assertSee('mailto:hello@moneysight.app', false)
        ->assertSee(route('bug-report'))
        ->assertSee(route('idea'));
    $this->get(route('bug-report'))->assertOk()->assertSee('data-test="bug-report-form"', false);
});

it('hides the e-mail row until a contact address is set', function (): void {
    config(['budget.contact_email' => null]);

    $this->get(route('contact'))->assertOk()->assertDontSee('data-test="contact-email"', false);
});

it('sends an idea tagged as an idea, with only the e-mail address', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    Livewire::withQueryParams(['honnan' => url('/ma')])
        ->test('pages::settings.feedback', ['kind' => 'idea'])
        ->assertSee('Share an idea')
        ->set('message', 'Show last month next to this one')
        ->call('send')
        ->assertSet('sent', true);

    Http::assertSent(function (Request $request): bool {
        $event = json_decode(explode("\n", $request->body())[2], true);

        return $event['tags']['kind'] === 'idea'
            && ! isset($event['contexts']['feedback']['url'])
            && $event['contexts']['browser'] === [];
    });
});

it('sends the page the user came from with a bug report', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    Livewire::withQueryParams(['honnan' => url('/ma')])
        ->test('pages::settings.feedback', ['kind' => 'bug'])
        ->set('message', 'The Next button does nothing')
        ->call('send');

    Http::assertSent(fn (Request $request): bool => json_decode(explode("\n", $request->body())[2], true)['contexts']['feedback']['url'] === url('/ma'));
});

it('sends a bug report to Sentry and thanks the user', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    Livewire::test('pages::settings.feedback', ['kind' => 'bug'])
        ->set('message', 'The Next button does nothing')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee('data-test="bug-report-sent"', false);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'The Next button does nothing'));
});

it('needs a description', function (): void {
    Http::fake();

    Livewire::test('pages::settings.feedback', ['kind' => 'bug'])->set('message', '')->call('send')->assertHasErrors('message');

    Http::assertNothingSent();
});

it('says so when the report cannot be sent', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response('', 500)]);

    Livewire::test('pages::settings.feedback', ['kind' => 'bug'])
        ->set('message', 'The Next button does nothing')
        ->call('send')
        ->assertHasErrors('message')
        ->assertSet('sent', false);
});

it('limits a user to five reports an hour', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    foreach (range(1, 5) as $attempt) {
        Livewire::test('pages::settings.feedback', ['kind' => 'bug'])->set('message', 'Report number '.$attempt)->call('send')->assertHasNoErrors();
    }

    Livewire::test('pages::settings.feedback', ['kind' => 'bug'])->set('message', 'One too many')->call('send')->assertHasErrors('message');

    Http::assertSentCount(5);
});
