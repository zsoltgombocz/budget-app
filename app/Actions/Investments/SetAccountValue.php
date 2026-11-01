<?php

namespace App\Actions\Investments;

use App\Models\Account;
use App\Models\AccountValue;
use App\Services\PeriodService;
use Illuminate\Validation\ValidationException;

/**
 * An investment account's value typed in by hand: today's point on its trend.
 */
final readonly class SetAccountValue
{
    public function __construct(private PeriodService $periods) {}

    public function handle(Account $account, int $value): void
    {
        if ($value < 0) {
            throw ValidationException::withMessages(['value' => __('Enter a valid amount.')]);
        }

        $user = $account->user()->firstOrFail();
        $today = $this->periods->today($user->settings())->toDateString();

        // The same write as a broker sync, so both store the day the same way.
        AccountValue::query()->withoutGlobalScopes()->upsert(
            [['user_id' => $account->user_id, 'account_id' => $account->id, 'date' => $today, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]],
            ['account_id', 'date'],
            ['value', 'updated_at'],
        );

        $account->update(['value' => $value, 'valued_on' => $today]);
    }
}
