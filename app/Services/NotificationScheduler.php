<?php

namespace App\Services;

use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\Period;
use App\Models\User;
use App\Notifications\BudgetPushNotification;
use App\Notifications\DailyReminder;
use App\Notifications\DueItemsReminder;
use App\Notifications\PaydayReminder;
use App\Notifications\PeriodEndReminder;
use App\Notifications\SurplusTransferReminder;
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

    public const string DUE_ITEMS_TIME = '08:00';

    public const string SURPLUS_REMINDER_TIME = '09:00';

    public function __construct(
        private PeriodService $periods,
        private OverviewService $overviews,
        private NotificationLogger $logger,
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
            && $this->claim('daily', $user, $today)) {
            if ($period->transactions()->whereDate('occurred_on', $today->toDateString())->exists()) {
                $this->logger->skipped($user, 'daily-reminder', 'recorded-today');
            } elseif ($user->dayMarks()->whereDate('date', $today->toDateString())->exists()) {
                $this->logger->skipped($user, 'daily-reminder', 'no-spend-today');
            } else {
                $this->send($user, new DailyReminder($today->toDateString()));
                $sent[] = 'daily';
            }
        }

        if ($settings->period_mode === PeriodMode::Payday
            && $period->starts_on->isSameDay($today)
            && $time >= self::PAYDAY_TIME
            && $this->claim('payday', $user, $today)) {
            $this->send($user, new PaydayReminder($this->transfers($period)));
            $sent[] = 'payday';
        }

        if ($settings->due_reminder_enabled && $time >= self::DUE_ITEMS_TIME) {
            $due = $this->dueToday($period, $today);

            if ($due !== [] && $this->claim('due-items', $user, $today)) {
                $this->send($user, new DueItemsReminder($due));
                $sent[] = 'due-items';
            }
        }

        if ($time >= self::SURPLUS_REMINDER_TIME) {
            $pending = $user->periodCloses()->with('surplusAccount')
                ->whereNull('surplus_transferred_at')->whereNotNull('surplus_account_id')->where('to_invest', '>', 0)
                ->whereDate('created_at', '<', $today->toDateString())
                ->get();

            foreach ($pending as $close) {
                if (Cache::add("notification:surplus:{$close->id}", true, now()->addDays(60))) {
                    $this->send($user, new SurplusTransferReminder($close->id, $close->to_invest, $close->surplusAccount->name ?? ''));
                    $sent[] = 'surplus-transfer';
                }
            }
        }

        if ($period->ends_on->isSameDay($today)
            && $time >= self::PERIOD_END_TIME
            && $this->claim('period-end', $user, $today)) {
            $overview = $this->overviews->forPeriod($user, $period, $today);
            $this->send($user, new PeriodEndReminder($overview->forecast->expectedLeftover));
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

    /**
     * Unticked fixed items whose due date is today.
     *
     * @return list<array{name: string, amount: int}>
     */
    private function dueToday(Period $period, CarbonImmutable $today): array
    {
        $due = [];

        foreach ($this->overviews->fixedItems($period, resolve(PlanService::class)->linesFor($period)) as $item) {
            if (! $item->paid && $item->dueOn?->isSameDay($today)) {
                $due[] = ['name' => $item->line->categoryName, 'amount' => $item->line->planned()];
            }
        }

        return $due;
    }

    private function send(User $user, BudgetPushNotification $notification): void
    {
        $this->logger->queued($user, $notification);
        $user->notify($notification);
    }

    private function claim(string $type, User $user, CarbonImmutable $day): bool
    {
        return Cache::add("notification:{$type}:{$user->id}:{$day->toDateString()}", true, now()->addDays(2));
    }
}
