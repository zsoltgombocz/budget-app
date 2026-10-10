<?php

namespace App\Actions\Budget;

use App\Models\Category;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetAlerts;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correct a recorded spending: amount, category, date and note. The same rules apply as when
 * recording it (open period only, the user's own category); a date in another open period
 * moves the spending to that period.
 *
 * A spending paid from a pocket stays paid from it: a new amount takes the difference from
 * the pocket or puts it back, so the pocket's balance and its withdrawal always match the
 * spending. An archived pocket's spending keeps its amount.
 */
final readonly class UpdateTransaction
{
    public function __construct(
        private PeriodService $periods,
        private BudgetAlerts $alerts,
    ) {}

    public function handle(User $user, Transaction $transaction, int $categoryId, int $amount, CarbonImmutable $date, ?string $note = null): Transaction
    {
        if ($transaction->user_id !== $user->id) {
            throw ValidationException::withMessages(['transaction' => __('This spending was not found.')]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        $currentPeriod = $transaction->period()->firstOrFail();

        if (! $currentPeriod->isOpen()) {
            throw ValidationException::withMessages(['date' => __('That period is already closed.')]);
        }

        // Keeping a category that was deleted since is fine; choosing one needs a live category.
        $category = $categoryId === $transaction->category_id
            ? $user->categories()->withTrashed()->find($categoryId)
            : $user->categories()->find($categoryId);

        if (! $category instanceof Category) {
            throw ValidationException::withMessages(['category' => __('Choose a category.')]);
        }

        $period = $this->periods->forDate($user, $date);

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['date' => __('That period is already closed.')]);
        }

        $pocket = $transaction->pocket_id === null ? null : $user->pockets()->withTrashed()->find($transaction->pocket_id);
        $difference = $amount - $transaction->amount;

        if ($pocket instanceof Pocket && $difference !== 0) {
            if ($pocket->trashed()) {
                throw ValidationException::withMessages(['amount' => __('The pocket is archived, so the amount of its spending cannot change.')]);
            }

            if ($difference > $pocket->balance) {
                throw ValidationException::withMessages(['amount' => __('The pocket does not have enough money.')]);
            }
        }

        $addedToCategory = $category->id === $transaction->category_id && $period->id === $transaction->period_id ? $difference : $amount;

        DB::transaction(function () use ($user, $transaction, $category, $amount, $date, $note, $period, $pocket, $difference): void {
            $transaction->update([
                'period_id' => $period->id,
                'category_id' => $category->id,
                'amount' => $amount,
                'occurred_on' => $date->toDateString(),
                'note' => filled($note) ? $note : null,
            ]);

            if ($pocket instanceof Pocket) {
                $user->pocketMovements()->where('transaction_id', $transaction->id)->update([
                    'period_id' => $period->id,
                    'amount' => -$amount,
                    'occurred_on' => $date->toDateString(),
                ]);

                if ($difference !== 0) {
                    $pocket->decrement('balance', $difference);
                }
            }

            // Spending on a day contradicts "I didn't spend today".
            $user->dayMarks()->whereDate('date', $date->toDateString())->delete();
        });

        if ($pocket === null && $addedToCategory > 0) {
            $this->alerts->afterSpending($user, $period, $category->id, $addedToCategory);
        }

        return $transaction->refresh();
    }
}
