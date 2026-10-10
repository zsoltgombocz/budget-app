<?php

namespace App\Services;

use App\Enums\LineType;
use App\Enums\PeriodStatus;
use App\Enums\PocketMovementType;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\User;
use App\Notifications\SurplusTransferReminder;
use App\Services\Data\Allocation;
use App\Services\Data\ClosePreview;
use App\Services\Data\PlanLine;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a period: actual vs plan, leftover allocation, pocket updates,
 * frozen plan snapshot and the next period opened with a copy of the plan.
 *
 * The breakdown's "undo" key records what the closing did that ReopenPeriod has to take back:
 * the period's regular end, the pocket movements it made and the pocket it created.
 */
final readonly class PeriodCloser
{
    /**
     * A category deviates when it is off by more than this share of its plan.
     */
    public const float DEVIATION_RATIO = 0.1;

    /**
     * A period can be closed in its last this many days (and any time after its end): closing
     * early means "the next salary arrived early", not "start a new month mid-month".
     */
    public const int CLOSE_WINDOW_DAYS = 7;

    public function __construct(
        private PlanService $plans,
        private BudgetCalculator $calculator,
        private AllocationCalculator $allocations,
        private PeriodService $periods,
    ) {}

    /**
     * The first day the period can be closed on.
     */
    public function closableFrom(Period $period): CarbonImmutable
    {
        return CarbonImmutable::parse($period->ends_on->subDays(self::CLOSE_WINDOW_DAYS - 1)->toDateString());
    }

    public function canClose(User $user, Period $period): bool
    {
        return $period->isOpen() && ! $this->periods->today($user->settings())->lessThan($this->closableFrom($period));
    }

    /**
     * Start and end of the period that follows the closing. Closed before its last day, the next
     * period starts on the closing day (what is recorded after closing goes there)
     * and runs to the regular end of the following period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function nextBounds(User $user, Period $period): array
    {
        $settings = $user->settings();
        $today = $this->periods->today($settings);
        [$start, $end] = $this->periods->boundsFor($settings->period_mode, $settings->payday_day, $period->ends_on->addDay());

        if ($today->greaterThanOrEqualTo($period->starts_on) && $today->lessThan($period->ends_on)) {
            return [$today, $end];
        }

        return [$start, $end];
    }

    /**
     * Opens the next period on the closing day; a period already opened for later dates is moved
     * to start then instead of overlapping it.
     */
    private function openEarly(User $user, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $next = $user->periods()->whereDate('starts_on', '>', $start->toDateString())->oldest('starts_on')->first();

        if ($next !== null) {
            $next->update(['starts_on' => $start->toDateString()]);

            return;
        }

        $this->periods->open($user, $start, $end, $user->settings()->income);
    }

    /**
     * @param  int|null  $toReserve  manual split of a positive leftover; the rest goes to the surplus target
     * @param  string|null  $surplusTarget  'account:ID', 'pocket:ID' or 'none' for this closing only; null uses the settings
     */
    /**
     * A deficit is taken from the reserve only when $coverDeficit is true (the user's choice on
     * the closing screen); otherwise it stays uncovered and nothing moves.
     */
    public function preview(User $user, Period $period, ?int $incomeActual = null, ?int $toReserve = null, ?string $surplusTarget = null, bool $coverDeficit = true): ClosePreview
    {
        $lines = $this->plans->linesFor($period);
        $settings = $user->settings();
        $topUps = $this->periods->topUps($period);
        $income = ($incomeActual ?? $period->income()) + $topUps;

        $spent = [];

        foreach ($period->transactions()->whereNull('pocket_id')->get(['category_id', 'amount']) as $transaction) {
            $spent[$transaction->category_id] = ($spent[$transaction->category_id] ?? 0) + $transaction->amount;
        }

        $categories = $this->categoryRows($user, $lines, $spent);
        $plannedTotal = array_sum(array_column($categories, 'planned'));
        $actualTotal = array_sum(array_column($categories, 'actual'));

        $pockets = $user->pockets()->get()->keyBy('id');
        $deposits = $this->pocketDeposits($lines, $pockets->all());

        $reserve = $pockets->first(fn (Pocket $pocket): bool => $pocket->is_reserve);
        $reserveBalanceAfterDeposits = $reserve === null ? 0 : $reserve->balance + ($deposits[$reserve->id]['amount'] ?? 0);

        $allocation = $this->allocations->allocate(
            leftover: $income - $actualTotal,
            reservePct: $settings->reserve_pct,
            hasReserve: $reserve !== null,
            reserveBalance: $reserveBalanceAfterDeposits,
            reserveTarget: $reserve?->target_amount,
            reserveFixed: $settings->reserve_fixed,
        );

        if (! $coverDeficit && $allocation->leftover < 0) {
            $allocation = new Allocation(
                leftover: $allocation->leftover,
                toReserve: 0,
                toSurplus: 0,
                fromReserve: 0,
                uncovered: -$allocation->leftover,
            );
        }

        if ($toReserve !== null && $reserve !== null && $allocation->leftover > 0) {
            $toReserve = max(0, min($toReserve, $allocation->leftover));
            $allocation = new Allocation(
                leftover: $allocation->leftover,
                toReserve: $toReserve,
                toSurplus: $allocation->leftover - $toReserve,
                fromReserve: 0,
                uncovered: 0,
            );
        }

        [$targetType, $targetId] = $this->resolveTarget($settings->surplus_account_id, $settings->surplus_pocket_id, $surplusTarget);
        $surplusPocket = $targetType === 'pocket' ? $pockets->get($targetId) : null;
        $newPocket = $targetType === 'new-pocket';
        $surplusAccount = $targetType === 'account' ? $user->accounts()->find($targetId) : null;

        $balancesAfter = [];

        foreach ($pockets as $pocket) {
            $balancesAfter[$pocket->id] = $pocket->balance + ($deposits[$pocket->id]['amount'] ?? 0);
        }

        if ($reserve !== null) {
            $balancesAfter[$reserve->id] += $allocation->toReserve - $allocation->fromReserve;
        }

        if ($surplusPocket !== null) {
            $balancesAfter[$surplusPocket->id] += $allocation->toSurplus;
        }

        $prepayReady = [];

        foreach ($pockets as $pocket) {
            if ($pocket->prepay_step !== null && $pocket->prepay_step > 0 && $balancesAfter[$pocket->id] >= $pocket->prepay_step) {
                $prepayReady[] = [
                    'pocket_id' => $pocket->id,
                    'name' => $pocket->name,
                    'loan_id' => $pocket->loan_id,
                    'balance' => $balancesAfter[$pocket->id],
                    'step' => $pocket->prepay_step,
                ];
            }
        }

        return new ClosePreview(
            incomePlanned: $period->income_planned,
            incomeActual: $income - $topUps,
            topUps: $topUps,
            plannedTotal: $plannedTotal,
            actualTotal: $actualTotal,
            categories: $categories,
            allocation: $allocation,
            reservePocketId: $reserve?->id,
            surplusTarget: [
                'type' => $newPocket ? 'new-pocket' : ($surplusPocket !== null ? 'pocket' : ($surplusAccount !== null ? 'account' : null)),
                'id' => $surplusPocket->id ?? $surplusAccount?->id,
                'name' => $newPocket ? $this->savingsName() : ($surplusPocket->name ?? $surplusAccount?->name),
            ],
            pocketDeposits: array_values($deposits),
            prepayReady: $prepayReady,
        );
    }

    public function close(User $user, Period $period, ?int $incomeActual = null, ?int $toReserve = null, ?string $surplusTarget = null, bool $coverDeficit = true): PeriodClose
    {
        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['period' => __('This period is already closed.')]);
        }

        if (! $this->canClose($user, $period)) {
            throw ValidationException::withMessages(['period' => __('The month can be closed from :date.', ['date' => Dates::short($this->closableFrom($period))])]);
        }

        $close = DB::transaction(function () use ($user, $period, $incomeActual, $toReserve, $surplusTarget, $coverDeficit): PeriodClose {
            $lines = $this->plans->linesFor($period);
            $preview = $this->preview($user, $period, $incomeActual, $toReserve, $surplusTarget, $coverDeficit);
            [$nextStart, $nextEnd] = $this->nextBounds($user, $period);
            $closesEarly = $nextStart->lessThan($period->ends_on);
            $closedOn = ($closesEarly ? $nextStart : $period->ends_on)->toDateString();
            $regularEndsOn = $period->ends_on->toDateString();
            $movementIds = [];
            $createdPocketId = null;

            foreach ($preview->pocketDeposits as $deposit) {
                $movementIds[] = $this->move($user, $deposit['pocket_id'], $period, $deposit['amount'], PocketMovementType::Deposit, $closedOn, __('Monthly saving'));
            }

            $allocation = $preview->allocation;

            if ($preview->reservePocketId !== null && $allocation->toReserve > 0) {
                $movementIds[] = $this->move($user, $preview->reservePocketId, $period, $allocation->toReserve, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            if ($preview->reservePocketId !== null && $allocation->fromReserve > 0) {
                $movementIds[] = $this->move($user, $preview->reservePocketId, $period, -$allocation->fromReserve, PocketMovementType::Withdraw, $closedOn, __('Covering the deficit'));
            }

            if ($preview->surplusTarget['type'] === 'new-pocket' && $allocation->toSurplus > 0) {
                $pocket = $user->pockets()->create(['name' => $this->savingsName(), 'sort' => $user->pockets()->count() + 1]);
                $createdPocketId = $pocket->id;
                $movementIds[] = $this->move($user, $pocket->id, $period, $allocation->toSurplus, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            if ($preview->surplusTarget['type'] === 'pocket' && $preview->surplusTarget['id'] !== null && $allocation->toSurplus > 0) {
                $movementIds[] = $this->move($user, $preview->surplusTarget['id'], $period, $allocation->toSurplus, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            $period->update([
                'ends_on' => $closedOn,
                'income_actual' => $preview->incomeActual,
                'status' => PeriodStatus::Closed,
                'plan_snapshot' => $this->calculator->snapshot($lines),
            ]);

            $close = new PeriodClose([
                'period_id' => $period->id,
                'planned_total' => $preview->plannedTotal,
                'actual_total' => $preview->actualTotal,
                'leftover' => $preview->leftover(),
                'to_reserve' => $allocation->toReserve,
                'to_invest' => $allocation->toSurplus,
                'surplus_account_id' => $preview->surplusTarget['type'] === 'account' ? $preview->surplusTarget['id'] : null,
                'from_reserve' => $allocation->fromReserve,
                'breakdown' => [
                    ...$preview->toBreakdown(),
                    'undo' => [
                        'regular_ends_on' => $regularEndsOn,
                        'movement_ids' => $movementIds,
                        'created_pocket_id' => $createdPocketId,
                    ],
                ],
            ]);
            $close->user_id = $user->id;
            $close->save();

            if ($closesEarly) {
                $this->openEarly($user, $nextStart, $nextEnd);
            } else {
                $this->periods->forDate($user, $nextStart);
            }

            return $close;
        });

        if ($close->awaitsTransfer() && $user->pushSubscriptions()->exists()) {
            $user->notify(new SurplusTransferReminder($close->id, $close->to_invest, $close->surplusAccount->name ?? ''));
        }

        return $close;
    }

    private function savingsName(): string
    {
        $name = __('Savings');

        return is_string($name) ? $name : 'Savings';
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function resolveTarget(?int $accountId, ?int $pocketId, ?string $override): array
    {
        if ($override === 'none') {
            return [null, null];
        }

        if ($override === 'new-pocket') {
            return ['new-pocket', null];
        }

        if ($override !== null && preg_match('/^(account|pocket):(\d+)$/', $override, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        return match (true) {
            $accountId !== null => ['account', $accountId],
            $pocketId !== null => ['pocket', $pocketId],
            default => [null, null],
        };
    }

    /**
     * @param  list<PlanLine>  $lines
     * @param  array<int, int>  $spent
     * @return list<array{category_id: int, name: string, type: string, planned: int, actual: int, diff: int, deviates: bool}>
     */
    private function categoryRows(User $user, array $lines, array $spent): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $row = $rows[$line->categoryId] ?? [
                'category_id' => $line->categoryId,
                'name' => $line->categoryName,
                'type' => $line->type->value,
                'planned' => 0,
                'actual' => 0,
            ];
            $row['planned'] += $line->planned();
            $row['actual'] = $line->type === LineType::Variable ? ($spent[$line->categoryId] ?? 0) : $row['planned'];
            $rows[$line->categoryId] = $row;
        }

        $unplanned = array_diff_key($spent, $rows);

        if ($unplanned !== []) {
            $names = $user->categories()->withTrashed()->whereIn('id', array_keys($unplanned))->get(['id', 'name', 'type'])->keyBy('id');

            foreach ($unplanned as $categoryId => $amount) {
                $rows[$categoryId] = [
                    'category_id' => $categoryId,
                    'name' => $names->get($categoryId)->name ?? '',
                    'type' => LineType::Variable->value,
                    'planned' => 0,
                    'actual' => $amount,
                ];
            }
        }

        return array_values(array_map(function (array $row): array {
            $diff = $row['actual'] - $row['planned'];

            return [
                ...$row,
                'diff' => $diff,
                'deviates' => $diff > 0 || ($row['planned'] > 0 && abs($diff) > $row['planned'] * self::DEVIATION_RATIO),
            ];
        }, $rows));
    }

    /**
     * Planned amounts of transfer and sinking lines linked to a pocket.
     *
     * @param  list<PlanLine>  $lines
     * @param  array<array-key, Pocket>  $pockets
     * @return array<int, array{pocket_id: int, name: string, amount: int}>
     */
    private function pocketDeposits(array $lines, array $pockets): array
    {
        $deposits = [];

        foreach ($lines as $line) {
            if ($line->pocketId === null || ! isset($pockets[$line->pocketId]) || $line->planned() <= 0) {
                continue;
            }

            $deposits[$line->pocketId] ??= ['pocket_id' => $line->pocketId, 'name' => $pockets[$line->pocketId]->name, 'amount' => 0];
            $deposits[$line->pocketId]['amount'] += $line->planned();
        }

        return $deposits;
    }

    /**
     * @return int the id of the new pocket movement
     */
    private function move(User $user, int $pocketId, Period $period, int $amount, PocketMovementType $type, string $date, string $note): int
    {
        $pocket = $user->pockets()->findOrFail($pocketId);

        $movement = $user->pocketMovements()->create([
            'pocket_id' => $pocket->id,
            'period_id' => $period->id,
            'amount' => $amount,
            'type' => $type,
            'occurred_on' => $date,
            'note' => $note,
        ]);

        $pocket->increment('balance', $amount);

        return $movement->id;
    }
}
