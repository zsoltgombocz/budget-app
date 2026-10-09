<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sends a bug report or an idea from the app to Sentry's User Feedback (tagged kind=bug|idea) as a "feedback" envelope item,
 * with an optional screenshot attached. Only what the user typed, their account id and e-mail,
 * the page they came from, the app version and the browser are sent.
 */
final readonly class BugReporter
{
    /**
     * @param  array{url?: string|null, user_agent?: string|null}  $context
     * @return string the Sentry event id of the report
     *
     * @throws RuntimeException when no DSN is configured or Sentry does not accept the report
     */
    public function send(User $user, string $message, ?UploadedFile $screenshot = null, array $context = [], string $kind = 'bug'): string
    {
        $dsn = $this->parseDsn(config()->string('services.bug_report.dsn', ''));
        $eventId = Str::lower(str_replace('-', '', (string) Str::uuid()));

        $feedback = array_filter([
            'message' => $message,
            'contact_email' => $user->email,
            'name' => $user->name,
            'url' => $context['url'] ?? null,
        ], filled(...));

        $event = [
            'event_id' => $eventId,
            'timestamp' => now()->getTimestamp(),
            'platform' => 'php',
            'level' => 'info',
            'environment' => app()->environment(),
            'release' => config('sentry.release'),
            'user' => ['id' => (string) $user->id, 'email' => $user->email],
            'tags' => array_filter(['kind' => $kind, 'app_version' => config()->string('app.version', ''), 'locale' => app()->getLocale()]),
            'contexts' => [
                'feedback' => $feedback,
                'browser' => array_filter(['name' => $context['user_agent'] ?? null]),
            ],
        ];

        $items = [[['type' => 'feedback'], json_encode($event, JSON_THROW_ON_ERROR)]];

        if ($screenshot instanceof UploadedFile) {
            $bytes = (string) file_get_contents($screenshot->getRealPath());
            $items[] = [[
                'type' => 'attachment',
                'length' => strlen($bytes),
                'filename' => 'screenshot.'.($screenshot->guessExtension() ?? 'png'),
                'content_type' => $screenshot->getMimeType() ?? 'image/png',
            ], $bytes];
        }

        $body = json_encode(['event_id' => $eventId, 'sent_at' => now()->toIso8601ZuluString(), 'dsn' => $dsn['dsn']], JSON_THROW_ON_ERROR)."\n";

        foreach ($items as [$header, $payload]) {
            $body .= json_encode($header, JSON_THROW_ON_ERROR)."\n".$payload."\n";
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'Content-Type' => 'application/x-sentry-envelope',
                'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key='.$dsn['key'].', sentry_client=moneysight-bug-report/1.0',
            ])
            ->withBody($body, 'application/x-sentry-envelope')
            ->post($dsn['endpoint']);

        if (! $response->successful()) {
            throw new RuntimeException('Sentry did not accept the bug report: HTTP '.$response->status());
        }

        return $eventId;
    }

    /**
     * @return array{dsn: string, key: string, endpoint: string}
     */
    private function parseDsn(string $dsn): array
    {
        $parts = parse_url($dsn);

        if ($dsn === '' || ! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            throw new RuntimeException('No bug report DSN is configured.');
        }

        $projectId = trim($parts['path'], '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return [
            'dsn' => $dsn,
            'key' => $parts['user'],
            'endpoint' => $parts['scheme'].'://'.$parts['host'].$port.'/api/'.$projectId.'/envelope/',
        ];
    }
}
