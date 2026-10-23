<?php

namespace App\Services;

use App\Actions\Month\CloseMonth;
use App\Enums\DueState;
use App\Models\Period;
use App\Models\User;
use App\Notifications\BudgetPushNotification;
use App\Notifications\CloseReminder;
use App\Notifications\DailyReminder;
use App\Notifications\DueItemsReminder;
use App\Notifications\PeriodEndReminder;
use App\Notifications\WeeklySummary;
use App\Services\Data\MonthLine;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Decides, per user and in the user's timezone, which scheduled notifications are due (2.0, S19).
 * Runs every minute; each notification is sent at most once (per day, week or month as fits).
 *
 * - the evening reminder, only when nothing was written in that day;
 * - the morning of a fixed line's day (8:30), the day's lines in one notification;
 * - the last day of the month (19:00) and the morning after it ended without a closing (10:00);
 * - the weekly summary on Sunday (18:00).
 */
final readonly class NotificationScheduler
{
    public const string DUE_ITEMS_TIME = '08:30';

    public const string CLOSE_TIME = '10:00';

    public const string WEEKLY_TIME = '18:00';

    public const string PERIOD_END_TIME = '19:00';

    public function __construct(
        private PeriodService $periods,
        private MonthService $months,
        private NotificationLogger $logger,
        private Streak $streak,
        private CloseMonth $closeMonth,
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
            && $this->claim('daily', $user, $today->toDateString())) {
            if ($period->transactions()->whereDate('occurred_on', $today->toDateString())->exists()) {
                $this->logger->skipped($user, 'daily-reminder', 'recorded-today');
            } elseif ($user->dayMarks()->whereDate('date', $today->toDateString())->exists()) {
                $this->logger->skipped($user, 'daily-reminder', 'no-spend-today');
            } else {
                $this->send($user, new DailyReminder($today->toDateString(), $this->streak->forUser($user, $today)));
                $sent[] = 'daily';
            }
        }

        if ($settings->due_reminder_enabled && $time >= self::DUE_ITEMS_TIME) {
            $due = $this->dueToday($user, $period, $today);

            if ($due !== [] && $this->claim('due-items', $user, $today->toDateString())) {
                $this->send($user, new DueItemsReminder($due));
                $sent[] = 'due-items';
            }
        }

        if ($time >= self::CLOSE_TIME) {
            $waiting = $user->periods()->where('status', 'open')->whereDate('ends_on', '<', $today->toDateString())->oldest('starts_on')->first();

            if ($waiting instanceof Period && Cache::add("notification:close:{$waiting->id}", true, now()->addDays(60))) {
                $leftover = $this->closeMonth->summary($user, $waiting)->leftover();
                $this->send($user, new CloseReminder($waiting->id, Str::ucfirst(Dates::monthCase($waiting->nameDate(), 'in')), $leftover));
                $sent[] = 'close';
            }
        }

        if ($settings->weekly_summary_enabled
            && $today->dayOfWeek === CarbonInterface::SUNDAY
            && $time >= self::WEEKLY_TIME
            && $this->claim('weekly', $user, $today->toDateString())) {
            $this->send($user, $this->weekly($user, $today));
            $sent[] = 'weekly';
        }

        if ($period->ends_on->isSameDay($today)
            && $time >= self::PERIOD_END_TIME
            && $this->claim('period-end', $user, $today->toDateString())) {
            $this->send($user, new PeriodEndReminder($this->months->view($user, $period, $today)->state->remaining));
            $sent[] = 'period-end';
        }

        return $sent;
    }

    /**
     * The fixed lines whose day is today and that are not done or skipped.
     *
     * @return list<array{name: string, amount: int, kind: string}>
     */
    private function dueToday(User $user, Period $period, CarbonImmutable $today): array
    {
        $due = [];

        foreach ($this->months->view($user, $period, $today)->lines as $line) {
            if (! in_array($line->state, [DueState::DueToday, DueState::ToDo], true)) {
                continue;
            }

            $due[] = ['name' => $line->name, 'amount' => $line->amount, 'kind' => $this->kind($line)];
        }

        return $due;
    }

    private function kind(MonthLine $line): string
    {
        return match (true) {
            $line->isSavings() => 'savings',
            $line->manualPay => 'manual',
            default => 'auto',
        };
    }

    private function weekly(User $user, CarbonImmutable $today): WeeklySummary
    {
        $from = $today->startOfWeek()->toDateString();
        $rows = $user->transactions()->with('category')->whereNull('pocket_id')
            ->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $today->toDateString())->get();
        $byCategory = [];
        $total = 0;

        foreach ($rows as $row) {
            $name = $row->category->name ?? '';
            $byCategory[$name] = ($byCategory[$name] ?? 0) + $row->amount;
            $total += $row->amount;
        }

        arsort($byCategory);

        return new WeeklySummary($total, $byCategory === [] ? null : (string) array_key_first($byCategory), $this->streak->forUser($user, $today));
    }

    private function send(User $user, BudgetPushNotification $notification): void
    {
        $this->logger->queued($user, $notification);
        $user->notify($notification);
    }

    private function claim(string $type, User $user, string $key): bool
    {
        return Cache::add("notification:{$type}:{$user->id}:{$key}", true, now()->addDays(2));
    }
}
