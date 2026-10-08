<?php

use App\Models\User;
use App\Services\BugReporter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.bug_report.dsn' => 'https://publickey@o1.ingest.de.sentry.io/42']);
    $this->user = User::factory()->make(['id' => 7, 'name' => 'Bea', 'email' => 'bea@example.com']);
});

/**
 * @return list<array<string, mixed>|string>
 */
function envelopeLines(Request $request): array
{
    return explode("\n", rtrim($request->body(), "\n"));
}

it('sends the report to the DSN as a Sentry feedback envelope', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    $eventId = new BugReporter()->send($this->user, 'The Next button does nothing', null, ['url' => 'https://moneysight.app/zaras/3', 'user_agent' => 'iPhone Safari']);

    Http::assertSent(function (Request $request) use ($eventId): bool {
        [$envelope, $itemHeader, $payload] = envelopeLines($request);
        $event = json_decode($payload, true);

        return $request->url() === 'https://o1.ingest.de.sentry.io/api/42/envelope/'
            && str_contains($request->header('X-Sentry-Auth')[0], 'sentry_key=publickey')
            && json_decode($envelope, true)['event_id'] === $eventId
            && json_decode($itemHeader, true) === ['type' => 'feedback']
            && $event['contexts']['feedback'] === ['message' => 'The Next button does nothing', 'contact_email' => 'bea@example.com', 'name' => 'Bea', 'url' => 'https://moneysight.app/zaras/3']
            && $event['user'] === ['id' => '7', 'email' => 'bea@example.com']
            && $event['contexts']['browser'] === ['name' => 'iPhone Safari'];
    });
});

it('attaches a screenshot to the report', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);

    new BugReporter()->send($this->user, 'Broken layout', UploadedFile::fake()->image('shot.png', 10, 10));

    Http::assertSent(function (Request $request): bool {
        $lines = envelopeLines($request);
        $attachment = json_decode($lines[3], true);

        return $attachment['type'] === 'attachment'
            && $attachment['filename'] === 'screenshot.png'
            && $attachment['content_type'] === 'image/png'
            && $attachment['length'] > 0;
    });
});

it('fails loudly when Sentry rejects the report or no DSN is set', function (): void {
    Http::fake(['o1.ingest.de.sentry.io/*' => Http::response('', 429)]);

    expect(fn (): string => new BugReporter()->send($this->user, 'Something'))->toThrow(RuntimeException::class, 'HTTP 429');

    config(['services.bug_report.dsn' => '']);

    expect(fn (): string => new BugReporter()->send($this->user, 'Something'))->toThrow(RuntimeException::class, 'No bug report DSN');
});
