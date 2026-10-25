<?php

namespace App\Actions\Budget;

use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetAlerts;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class RecordTransaction
{
    public function __construct(
        private PeriodService $periods,
        private BudgetAlerts $alerts,
    ) {}

    /**
     * Record a spending. Idempotent on the client generated UUID, so a retried
     * request (double tap, offline resend) never creates a duplicate.
     */
    public function handle(
        User $user,
        int $categoryId,
        int $amount,
        ?CarbonImmutable $date = null,
        ?string $note = null,
        ?string $clientUuid = null,
    ): Transaction {
        if ($clientUuid !== null) {
            $existing = $user->transactions()->where('client_uuid', $clientUuid)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        $category = $user->categories()->find($categoryId);

        if (! $category instanceof Category) {
            throw ValidationException::withMessages(['category' => __('Choose a category.')]);
        }

        $today = $this->periods->today($user->settings());
        $date ??= $today;

        if ($date->greaterThan($today)) {
            throw ValidationException::withMessages(['date' => __('You cannot record a future day.')]);
        }

        $current = $this->periods->current($user);
        // Only the current period is opened on demand; an earlier day must fall into a period that exists.
        $period = $date->greaterThanOrEqualTo($current->starts_on)
            ? $this->periods->forDate($user, $date)
            : $user->periods()->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())->latest('starts_on')->first();

        if ($period === null) {
            throw ValidationException::withMessages(['date' => __('That day is before your first month in the app.')]);
        }

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['date' => __('That period is already closed.')]);
        }

        $transaction = $user->transactions()->create([
            'period_id' => $period->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'occurred_on' => $date->toDateString(),
            'note' => filled($note) ? $note : null,
            'source' => TransactionSource::Manual,
            'client_uuid' => $clientUuid,
        ]);

        // Spending on a day contradicts "I didn't spend today".
        $user->dayMarks()->whereDate('date', $date->toDateString())->delete();

        $this->alerts->afterSpending($user, $period, $category->id, $amount);

        return $transaction;
    }
}
