<?php

namespace App\Actions\Budget;

use App\Enums\LoanEventType;
use App\Enums\PeriodStatus;
use App\Enums\PocketMovementType;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\PocketMovement;
use App\Models\User;
use App\Services\BudgetCalculator;
use App\Services\Data\ReopenPreview;
use App\Services\PeriodService;
use App\Services\PlanService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undo the most recent closing ("Zárás visszavonása"), meant for fixing a month right after an
 * early closing. It takes back exactly what PeriodCloser::close() did:
 *
 * - deletes the pocket movements of the closing and puts the pocket balances back,
 * - deletes the "Savings" pocket the closing created, if nothing else uses it,
 * - opens the period again until its regular end, with the live plan and no actual income,
 * - moves what was recorded in the next period on those days back into it,
 * - deletes the next period (or, if it holds entries dated later, starts it after the regular end),
 * - deletes the closing record, which also ends the leftover transfer reminder.
 *
 * It refuses when something happened since the closing that cannot be undone cleanly.
 */
final readonly class ReopenPeriod
{
    /** Legacy closings (no stored undo data): their movements were made just before the record. */
    private const int LEGACY_WINDOW_SECONDS = 120;

    public function __construct(
        private PeriodService $periods,
        private PlanService $plans,
        private BudgetCalculator $calculator,
    ) {}

    /**
     * Whether to offer the undo at all: the latest closed period, until its regular end.
     * The other refusals show up only when the user asks for it.
     */
    public function isOffered(User $user, Period $period): bool
    {
        if ($period->isOpen() || ! $this->isLatestClosed($user, $period)) {
            return false;
        }

        $close = $period->close()->first();

        return $close instanceof PeriodClose
            && $this->periods->today($user->settings())->lessThanOrEqualTo($this->regularEnd($user, $period, $close));
    }

    /**
     * What undoing the closing would do.
     *
     * @throws ValidationException when the closing cannot be undone (the reason is in the "reopen" key)
     */
    public function preview(User $user, Period $period): ReopenPreview
    {
        $close = $period->isOpen() ? null : $period->close()->first();

        if (! $close instanceof PeriodClose) {
            $this->refuse(__('This period is not closed.'));
        }

        if (! $this->isLatestClosed($user, $period)) {
            $this->refuse(__('Only the most recent closing can be undone.'));
        }

        $regularEnd = $this->regularEnd($user, $period, $close);

        if ($this->periods->today($user->settings())->greaterThan($regularEnd)) {
            $this->refuse(__('A closing can only be undone until the month’s regular end (:date).', ['date' => Dates::short($regularEnd)]));
        }

        if ($close->surplus_transferred_at !== null) {
            $this->refuse(__('The leftover is already marked as transferred, so the closing can no longer be undone.'));
        }

        if ($user->currencyConversions()->where('created_at', '>=', $close->created_at)->exists()) {
            $this->refuse(__('The base currency changed since the closing, so it can no longer be undone.'));
        }

        if ($user->loanEvents()->where('type', LoanEventType::Prepayment)->where('created_at', '>=', $close->created_at)->exists()) {
            $this->refuse(__('A loan prepayment was recorded since the closing, so it can no longer be undone.'));
        }

        [$movements, $createdPocketId] = $this->closingMovements($user, $period, $close);
        $movementIds = array_values(array_map(fn (PocketMovement $movement): int => $movement->id, $movements->all()));
        $pocketIds = array_values(array_unique(array_map(fn (PocketMovement $movement): int => $movement->pocket_id, $movements->all())));
        $pockets = Pocket::query()->withTrashed()->where('user_id', $user->id)->whereIn('id', $pocketIds)->get()->keyBy('id');

        foreach ($pockets as $pocket) {
            if ($pocket->trashed()) {
                $this->refuse(__('The :pocket pocket was deleted since the closing, so the closing can no longer be undone.', ['pocket' => $pocket->name]));
            }
        }

        if ($movementIds !== []) {
            $later = PocketMovement::query()->where('user_id', $user->id)
                ->whereIn('pocket_id', $pocketIds)
                ->where('id', '>', min($movementIds))
                ->whereNotIn('id', $movementIds)
                ->first(['pocket_id']);

            if ($later !== null) {
                $this->refuse(__('Money was moved in the :pocket pocket since the closing, so the closing can no longer be undone.', ['pocket' => $pockets->get($later->pocket_id)->name ?? '']));
            }
        }

        $next = $user->periods()->whereDate('starts_on', '>', $period->starts_on->toDateString())->oldest('starts_on')->first();

        if ($next !== null && $next->lineStatuses()->where(fn ($query) => $query->whereNotNull('paid_on')->orWhereNotNull('amount_actual'))->exists()) {
            $this->refuse(__('A fixed item was already ticked off in the new month. Untick it first, then undo the closing.'));
        }

        $pocketChanges = [];

        foreach ($movements as $movement) {
            $pocketChanges[$movement->pocket_id] ??= ['pocket_id' => $movement->pocket_id, 'name' => $pockets->get($movement->pocket_id)->name ?? '', 'amount' => 0];
            $pocketChanges[$movement->pocket_id]['amount'] -= $movement->amount;
        }

        $removedPocket = $createdPocketId !== null ? $this->removablePocket($user, $createdPocketId, $movementIds) : null;
        $boundary = $regularEnd->toDateString();

        return new ReopenPreview(
            period: $period,
            close: $close,
            regularEnd: $regularEnd,
            movementIds: $movementIds,
            pocketChanges: array_values($pocketChanges),
            removedPocketId: $removedPocket?->id,
            removedPocketName: $removedPocket?->name,
            nextPeriod: $next,
            removesNextPeriod: $next !== null
                && ! $next->transactions()->whereDate('occurred_on', '>', $boundary)->exists()
                && ! PocketMovement::query()->where('period_id', $next->id)->whereDate('occurred_on', '>', $boundary)->exists(),
            movedEntries: $next === null ? 0 : $next->transactions()->whereDate('occurred_on', '<=', $boundary)->count()
                + PocketMovement::query()->where('period_id', $next->id)->whereNull('transaction_id')->whereDate('occurred_on', '<=', $boundary)->count(),
        );
    }

    /**
     * Undo the closing in one transaction and return the reopened period.
     *
     * @throws ValidationException when the closing cannot be undone
     */
    public function handle(User $user, Period $period): Period
    {
        return DB::transaction(function () use ($user, $period): Period {
            $period = $user->periods()->lockForUpdate()->findOrFail($period->id);
            $preview = $this->preview($user, $period);

            PocketMovement::query()->whereIn('id', $preview->movementIds)->delete();

            foreach ($preview->pocketChanges as $change) {
                Pocket::query()->whereKey($change['pocket_id'])->increment('balance', $change['amount']);
            }

            if ($preview->removedPocketId !== null) {
                Pocket::query()->whereKey($preview->removedPocketId)->forceDelete();
            }

            $boundary = $preview->regularEnd->toDateString();
            $next = $preview->nextPeriod;

            if ($next instanceof Period) {
                $next->transactions()->whereDate('occurred_on', '<=', $boundary)->update(['period_id' => $period->id]);
                PocketMovement::query()->where('period_id', $next->id)->whereDate('occurred_on', '<=', $boundary)->update(['period_id' => $period->id]);

                if ($preview->removesNextPeriod) {
                    $next->lineStatuses()->delete();
                    $next->delete();
                } else {
                    $next->update(['starts_on' => $preview->regularEnd->addDay()->toDateString()]);
                }
            }

            $preview->close->delete();

            $period->update([
                'ends_on' => $boundary,
                'status' => PeriodStatus::Open,
                'income_actual' => null,
                'plan_snapshot' => $this->calculator->snapshot($this->plans->liveLines($user, $period->starts_on, $preview->regularEnd)),
            ]);

            return $period;
        });
    }

    /**
     * The day the period would have ended without the closing. Closings made before this
     * feature did not store it: the regular period containing the closing day ends then.
     */
    public function regularEnd(User $user, Period $period, PeriodClose $close): CarbonImmutable
    {
        $undo = $close->breakdown['undo'] ?? null;

        if (is_array($undo) && is_string($undo['regular_ends_on'] ?? null)) {
            return CarbonImmutable::parse($undo['regular_ends_on']);
        }

        $settings = $user->settings();

        return $this->periods->boundsFor($settings->period_mode, $settings->payday_day, $period->ends_on)[1];
    }

    private function isLatestClosed(User $user, Period $period): bool
    {
        return ! $user->periods()
            ->where('status', PeriodStatus::Closed)
            ->whereDate('starts_on', '>', $period->starts_on->toDateString())
            ->exists();
    }

    /**
     * The pocket movements the closing made and the pocket it created.
     *
     * @return array{0: Collection<int, PocketMovement>, 1: int|null}
     */
    private function closingMovements(User $user, Period $period, PeriodClose $close): array
    {
        $undo = $close->breakdown['undo'] ?? null;

        if (is_array($undo) && is_array($undo['movement_ids'] ?? null)) {
            /** @var list<int> $ids */
            $ids = $undo['movement_ids'];
            $movements = PocketMovement::query()->where('user_id', $user->id)->whereIn('id', $ids)->get();

            if ($movements->count() !== count($ids)) {
                $this->refuse(__('The closing’s pocket movements could not be identified, so it cannot be undone automatically.'));
            }

            return [$movements, is_int($undo['created_pocket_id'] ?? null) ? $undo['created_pocket_id'] : null];
        }

        return $this->legacyClosingMovements($user, $period, $close);
    }

    /**
     * Closings made before the undo data was stored: the movements dated on the closing day,
     * made just before the record, checked against the amounts the record says were moved.
     *
     * @return array{0: Collection<int, PocketMovement>, 1: int|null}
     */
    private function legacyClosingMovements(User $user, Period $period, PeriodClose $close): array
    {
        $createdAt = CarbonImmutable::parse($close->created_at);
        $movements = PocketMovement::query()->where('user_id', $user->id)
            ->where('period_id', $period->id)
            ->whereDate('occurred_on', $period->ends_on->toDateString())
            ->whereNull('transaction_id')
            ->where('to_budget', false)
            ->whereIn('type', [PocketMovementType::Deposit, PocketMovementType::Withdraw])
            ->whereBetween('created_at', [$createdAt->subSeconds(self::LEGACY_WINDOW_SECONDS), $createdAt])
            ->get();

        $breakdown = $close->breakdown;
        $target = $breakdown['surplus_target'] ?? null;
        $targetType = is_array($target) ? ($target['type'] ?? null) : null;
        $toPocket = in_array($targetType, ['pocket', 'new-pocket'], true) && $close->to_invest > 0;

        $expectedSum = $close->to_reserve - $close->from_reserve + ($toPocket ? $close->to_invest : 0);
        $expectedCount = ($close->to_reserve > 0 ? 1 : 0) + ($close->from_reserve > 0 ? 1 : 0) + ($toPocket ? 1 : 0);

        foreach (is_array($breakdown['pocket_deposits'] ?? null) ? $breakdown['pocket_deposits'] : [] as $deposit) {
            $expectedSum += is_array($deposit) && is_int($deposit['amount'] ?? null) ? $deposit['amount'] : 0;
            $expectedCount++;
        }

        if (array_sum(array_map(fn (PocketMovement $movement): int => $movement->amount, $movements->all())) !== $expectedSum || $movements->count() !== $expectedCount) {
            $this->refuse(__('The closing’s pocket movements could not be identified, so it cannot be undone automatically.'));
        }

        $createdPocketId = null;

        if ($targetType === 'new-pocket' && $close->to_invest > 0) {
            $created = Pocket::query()->where('user_id', $user->id)
                ->whereIn('id', $movements->pluck('pocket_id'))
                ->whereBetween('created_at', [$createdAt->subSeconds(self::LEGACY_WINDOW_SECONDS), $createdAt])
                ->first();
            $createdPocketId = $created?->id;
        }

        return [$movements, $createdPocketId];
    }

    /**
     * The pocket the closing created, if nothing but the closing has touched it since:
     * no other movements, spending, plan lines, loan or leftover setting point at it.
     *
     * @param  list<int>  $closingMovementIds
     */
    private function removablePocket(User $user, int $pocketId, array $closingMovementIds): ?Pocket
    {
        $pocket = $user->pockets()->find($pocketId);

        if (! $pocket instanceof Pocket || $pocket->loan_id !== null) {
            return null;
        }

        $used = PocketMovement::query()->where('pocket_id', $pocket->id)->whereNotIn('id', $closingMovementIds)->exists()
            || $user->transactions()->where('pocket_id', $pocket->id)->exists()
            || $user->budgetLines()->where('pocket_id', $pocket->id)->exists()
            || $user->settings()->surplus_pocket_id === $pocket->id;

        return $used ? null : $pocket;
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['reopen' => $message]);
    }
}
