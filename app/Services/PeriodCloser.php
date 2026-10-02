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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a period: actual vs plan, leftover allocation, pocket updates,
 * frozen plan snapshot and the next period opened with a copy of the plan.
 */
final readonly class PeriodCloser
{
    /**
     * A category deviates when it is off by more than this share of its plan.
     */
    public const float DEVIATION_RATIO = 0.1;

    public function __construct(
        private PlanService $plans,
        private BudgetCalculator $calculator,
        private AllocationCalculator $allocations,
        private PeriodService $periods,
    ) {}

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

        $close = DB::transaction(function () use ($user, $period, $incomeActual, $toReserve, $surplusTarget, $coverDeficit): PeriodClose {
            $lines = $this->plans->linesFor($period);
            $preview = $this->preview($user, $period, $incomeActual, $toReserve, $surplusTarget, $coverDeficit);
            $closedOn = $period->ends_on->toDateString();

            foreach ($preview->pocketDeposits as $deposit) {
                $this->move($user, $deposit['pocket_id'], $period, $deposit['amount'], PocketMovementType::Deposit, $closedOn, __('Monthly saving'));
            }

            $allocation = $preview->allocation;

            if ($preview->reservePocketId !== null && $allocation->toReserve > 0) {
                $this->move($user, $preview->reservePocketId, $period, $allocation->toReserve, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            if ($preview->reservePocketId !== null && $allocation->fromReserve > 0) {
                $this->move($user, $preview->reservePocketId, $period, -$allocation->fromReserve, PocketMovementType::Withdraw, $closedOn, __('Covering the deficit'));
            }

            if ($preview->surplusTarget['type'] === 'new-pocket' && $allocation->toSurplus > 0) {
                $pocket = $user->pockets()->create(['name' => $this->savingsName(), 'sort' => $user->pockets()->count() + 1]);
                $this->move($user, $pocket->id, $period, $allocation->toSurplus, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            if ($preview->surplusTarget['type'] === 'pocket' && $preview->surplusTarget['id'] !== null && $allocation->toSurplus > 0) {
                $this->move($user, $preview->surplusTarget['id'], $period, $allocation->toSurplus, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            $period->update([
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
                'breakdown' => $preview->toBreakdown(),
            ]);
            $close->user_id = $user->id;
            $close->save();

            $this->periods->forDate($user, $period->ends_on->addDay());

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

    private function move(User $user, int $pocketId, Period $period, int $amount, PocketMovementType $type, string $date, string $note): void
    {
        $pocket = $user->pockets()->findOrFail($pocketId);

        $user->pocketMovements()->create([
            'pocket_id' => $pocket->id,
            'period_id' => $period->id,
            'amount' => $amount,
            'type' => $type,
            'occurred_on' => $date,
            'note' => $note,
        ]);

        $pocket->increment('balance', $amount);
    }
}
