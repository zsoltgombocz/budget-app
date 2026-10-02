<?php

namespace App\Livewire\Pulse;

use App\Support\SecurityEvents as Events;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Pulse card: failed and suspicious sign-in activity by event and IP address.
 */
#[Lazy]
class SecurityEvents extends Card
{
    public function render(): Renderable
    {
        [$events, $time, $runAt] = $this->remember(
            fn () => $this->aggregate(Events::TYPE, ['max', 'count'], 'count')
                ->map(function (mixed $row): object {
                    $values = is_object($row) ? get_object_vars($row) : [];
                    $key = is_string($values['key'] ?? null) ? json_decode($values['key'], true) : null;

                    return (object) [
                        'event' => Events::label(is_array($key) && is_string($key[0] ?? null) ? $key[0] : '?'),
                        'ip' => is_array($key) && is_string($key[1] ?? null) ? $key[1] : '?',
                        'latest' => CarbonImmutable::createFromTimestamp(is_numeric($values['max'] ?? null) ? (int) $values['max'] : 0),
                        'count' => is_numeric($values['count'] ?? null) ? (int) $values['count'] : 0,
                    ];
                }),
        );

        return View::make('livewire.pulse.security-events', [
            'time' => $time,
            'runAt' => $runAt,
            'events' => $events,
        ]);
    }
}
