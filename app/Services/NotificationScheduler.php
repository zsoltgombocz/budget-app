<?php

namespace App\Services;

use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\Period;
use App\Models\User;
use App\Notifications\DailyReminder;
use App\Notifications\PaydayReminder;
use App\Notifications\PeriodEndReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Decides, per user and in the user's timezone, which scheduled notifications are due.
 * Runs every minute; each notification is sent at most once per local day.
 */
final readonly class NotificationScheduler
{
    public const string PAYDAY_TIME = '08:00';

    public const string PERIOD_END_TIME = '09:00';

    public function __construct(
        private PeriodService $periods,
        private OverviewService $overviews,
    ) {}

    /**
     * @return list<string> the notification types sent
     */
    public function run(User $user, ?CarbonImmutable $now = null): array
    {
        $settings = $user->settings();

        if (! $settings->isOnboarded()) {
            return [];
        }

        $local = ($now ?? CarbonImmutable::now())->setTimezone($settings->timezone);
        $today = CarbonImmutable::parse($local->toDateString());
        $time = $local->format('H:i');
        $period = $this->periods->forDate($user, $today);
        $sent = [];

        if ($settings->reminder_enabled
            && $time >= substr($settings->reminder_time, 0, 5)
            && ! $period->transactions()->whereDate('occurred_on', $today->toDateString())->exists()
            && ! $user->dayMarks()->whereDate('date', $today->toDateString())->exists()
            && $this->claim('daily', $user, $today)) {
            $user->notify(new DailyReminder($today->toDateString()));
            $sent[] = 'daily';
        }

        if ($settings->period_mode === PeriodMode::Payday
            && $period->starts_on->isSameDay($today)
            && $time >= self::PAYDAY_TIME
            && $this->claim('payday', $user, $today)) {
            $user->notify(new PaydayReminder($this->transfers($period)));
            $sent[] = 'payday';
        }

        if ($period->ends_on->isSameDay($today)
            && $time >= self::PERIOD_END_TIME
            && $this->claim('period-end', $user, $today)) {
            $overview = $this->overviews->forPeriod($user, $period, $today);
            $user->notify(new PeriodEndReminder($overview->forecast->expectedLeftover));
            $sent[] = 'period-end';
        }

        return $sent;
    }

    /**
     * @return list<array{name: string, amount: int}>
     */
    private function transfers(Period $period): array
    {
        $transfers = [];

        foreach ($this->overviews->fixedItems($period, resolve(PlanService::class)->linesFor($period)) as $item) {
            if (in_array($item->line->type, [LineType::Transfer, LineType::Sinking], true)) {
                $transfers[] = ['name' => $item->line->categoryName, 'amount' => $item->line->planned()];
            }
        }

        return $transfers;
    }

    private function claim(string $type, User $user, CarbonImmutable $day): bool
    {
        return Cache::add("notification:{$type}:{$user->id}:{$day->toDateString()}", true, now()->addDays(2));
    }
}
