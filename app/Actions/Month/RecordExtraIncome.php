<?php

namespace App\Actions\Month;

use App\Models\ExtraIncome;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * "Kaptam pénzt": money on top of the month's income. It counts at once, the daily allowance too.
 */
final readonly class RecordExtraIncome
{
    public function __construct(private PeriodService $periods) {}

    public function record(User $user, int $amount, ?CarbonImmutable $on = null, ?string $note = null, ?string $clientUuid = null): ExtraIncome
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount.')]);
        }

        if ($clientUuid !== null && ($existing = $user->extraIncomes()->where('client_uuid', $clientUuid)->first()) instanceof ExtraIncome) {
            return $existing;
        }

        $on ??= $this->periods->today($user->settings());
        $period = $this->periods->forDate($user, $on);

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['occurred_on' => __('This month is closed.')]);
        }

        $income = new ExtraIncome(['period_id' => $period->id, 'amount' => $amount, 'occurred_on' => $on->toDateString(), 'note' => $note, 'client_uuid' => $clientUuid]);
        $income->user_id = $user->id;
        $income->save();

        return $income;
    }

    public function delete(User $user, int $extraIncomeId): void
    {
        $income = $user->extraIncomes()->with('period')->findOrFail($extraIncomeId);

        if ($income->period?->isOpen() === false) {
            throw ValidationException::withMessages(['occurred_on' => __('This month is closed.')]);
        }

        $income->delete();
    }
}
