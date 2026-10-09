<?php

namespace App\Services;

use App\Enums\PeriodMode;
use App\Enums\PeriodStatus;
use App\Models\BudgetSetting;
use App\Models\Period;
use App\Models\PocketMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class PeriodService
{
    public function __construct(
        private PlanService $plans,
        private BudgetCalculator $calculator,
    ) {}

    /**
     * Start and end date (inclusive) of the period containing the given date.
     *
     * Payday mode runs from payday to the day before the next payday. A payday beyond the
     * month's last day (e.g. 31) falls on the last day of shorter months.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function boundsFor(PeriodMode $mode, ?int $paydayDay, CarbonInterface $date): array
    {
        $date = CarbonImmutable::parse($date->toDateString());

        if ($mode === PeriodMode::Calendar || $paydayDay === null) {
            return [$date->startOfMonth(), $date->endOfMonth()->startOfDay()];
        }

        $thisPayday = $this->paydayIn($date, $paydayDay);

        if ($date->greaterThanOrEqualTo($thisPayday)) {
            $start = $thisPayday;
            $nextPayday = $this->paydayIn($date->startOfMonth()->addMonth(), $paydayDay);
        } else {
            $start = $this->paydayIn($date->startOfMonth()->subMonth(), $paydayDay);
            $nextPayday = $thisPayday;
        }

        return [$start, $nextPayday->subDay()];
    }

    /**
     * Today's date in the user's timezone.
     */
    public function today(BudgetSetting $settings): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($settings->timezone)->toDateString());
    }

    public function current(User $user): Period
    {
        return $this->forDate($user, $this->today($user->settings()));
    }

    /**
     * The period containing the date, opened with a snapshot of the current plan if missing.
     * On the day of an early closing both periods contain it; the new one wins.
     */
    public function forDate(User $user, CarbonInterface $date): Period
    {
        $existing = $user->periods()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->latest('starts_on')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $settings = $user->settings();
        [$start, $end] = $this->boundsFor($settings->period_mode, $settings->payday_day, $date);

        return $this->open($user, $start, $end, $settings->income);
    }

    public function open(User $user, CarbonImmutable $start, CarbonImmutable $end, int $income): Period
    {
        $lines = $this->plans->liveLines($user, $start, $end);

        return $user->periods()->create([
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'income_planned' => $income,
            'status' => PeriodStatus::Open,
            'plan_snapshot' => $this->calculator->snapshot($lines),
        ]);
    }

    /**
     * Money taken out of pockets into this period's budget (counts as extra income).
     */
    public function topUps(Period $period): int
    {
        return -(int) PocketMovement::query()
            ->withoutGlobalScopes()
            ->where('period_id', $period->id)
            ->where('to_budget', true)
            ->sum('amount');
    }

    /**
     * Income the period can spend: actual (or planned) income plus pocket top-ups.
     */
    public function availableIncome(Period $period): int
    {
        return $period->income() + $this->topUps($period);
    }

    private function paydayIn(CarbonImmutable $month, int $paydayDay): CarbonImmutable
    {
        return $month->startOfMonth()->setDay(min($paydayDay, $month->daysInMonth));
    }
}
