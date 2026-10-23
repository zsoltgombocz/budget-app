<?php

namespace App\Actions\Month;

use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The 2.0 onboarding's answers become the plan: income, when the month starts, the fixed expenses
 * (with their days), the savings line (tied to a goal), and the spending categories to record into.
 */
final readonly class StartBudget
{
    /**
     * The spending categories every budget starts with; they can be changed later.
     *
     * @var list<array{name: string, icon: string}>
     */
    public const array CATEGORIES = [
        ['name' => 'Shop', 'icon' => 'shopping_cart'],
        ['name' => 'Eating out and coffee', 'icon' => 'restaurant'],
        ['name' => 'Transport', 'icon' => 'directions_bus'],
        ['name' => 'Entertainment', 'icon' => 'movie'],
        ['name' => 'Health', 'icon' => 'local_pharmacy'],
        ['name' => 'Clothes', 'icon' => 'checkroom'],
        ['name' => 'Household', 'icon' => 'cleaning_services'],
        ['name' => 'Presents', 'icon' => 'redeem'],
        ['name' => 'Other', 'icon' => 'category'],
    ];

    public function __construct(private SaveFixedLine $saveFixedLine, private SaveGoal $saveGoal, private PeriodService $periods) {}

    /**
     * @param  list<array{name: string, icon?: string|null, amount: int, due_day?: int|null, loan?: bool}>  $fixed  lines with 0 are left out
     * @param  array{amount: int, goal?: string|null, due_day?: int|null}  $savings  amount 0: no savings line
     */
    public function handle(User $user, int $income, PeriodMode $periodMode, ?int $paydayDay, array $fixed, array $savings, Currency $currency = Currency::HUF): void
    {
        if ($income < 1) {
            throw ValidationException::withMessages(['income' => __('Enter your monthly income.')]);
        }

        if ($periodMode === PeriodMode::Payday && ($paydayDay === null || $paydayDay < 1 || $paydayDay > 31)) {
            throw ValidationException::withMessages(['payday_day' => __('Choose a day between 1 and 31.')]);
        }

        DB::transaction(function () use ($user, $income, $periodMode, $paydayDay, $fixed, $savings, $currency): void {
            $user->settings()->update([
                'income' => $income,
                'period_mode' => $periodMode,
                'payday_day' => $periodMode === PeriodMode::Payday ? $paydayDay : null,
                'currency' => $currency,
                'locale' => app()->getLocale(),
            ]);

            if (! $user->categories()->where('type', LineType::Variable)->exists()) {
                foreach (self::CATEGORIES as $sort => $category) {
                    $user->categories()->create(['name' => __($category['name']), 'type' => LineType::Variable, 'icon' => $category['icon'], 'sort' => $sort, 'is_quick_entry' => true]);
                }
            }

            foreach ($fixed as $line) {
                if ($line['amount'] <= 0) {
                    continue;
                }

                $saved = $this->saveFixedLine->save($user, [
                    'name' => $line['name'],
                    'type' => ($line['loan'] ?? false) ? LineType::Loan : LineType::Fixed,
                    'amount' => $line['amount'],
                    'due_day' => $line['due_day'] ?? null,
                    'manual_pay' => false,
                ]);

                if (($line['icon'] ?? null) !== null) {
                    $saved->category?->update(['icon' => $line['icon']]);
                }
            }

            if ($savings['amount'] > 0) {
                $this->saveGoal->create($user, [
                    'name' => ($savings['goal'] ?? null) ?: __('Emergency fund'),
                    'target' => null,
                    'target_month' => null,
                    'monthly' => $savings['amount'],
                    'starting' => 0,
                ]);

                if (($savings['due_day'] ?? null) !== null) {
                    $user->budgetLines()->whereNotNull('pocket_id')->update(['due_day' => $savings['due_day']]);
                }
            }

            $user->settings()->update(['onboarded_at' => now(), 'onboarding_draft' => null]);
            $user->unsetRelation('budgetSetting');
            $this->periods->current($user);
        });
    }
}
