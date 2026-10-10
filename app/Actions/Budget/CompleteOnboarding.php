<?php

namespace App\Actions\Budget;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\CategoryTemplate;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;

final readonly class CompleteOnboarding
{
    public function __construct(
        private ApplyCategoryTemplate $applyTemplate,
        private PeriodService $periods,
    ) {}

    /**
     * Build the user's plan from the wizard answers and open the first period.
     *
     * A fixed-type line (fixed, transfer, loan, sinking) answered with 0 stays out of the plan,
     * as the wizard tells the user. Variable budgets are created even at 0 (they are the
     * categories spending is recorded in), and so is the reserve (its pocket).
     *
     * @param  array<int, int>  $amounts  template item index => planned amount
     * @param  'investment'|'pocket'  $surplusTarget
     * @param  list<int>|null  $included  template item indexes to create, null for all
     * @param  array{principal?: int|null, thm?: float|null, months?: int|null}  $loanDetails  optional details of the loan line's loan
     * @param  int|null  $reserveFixed  a fixed amount of the leftover for the reserve instead of the share
     */
    public function handle(
        User $user,
        int $income,
        PeriodMode $periodMode,
        ?int $paydayDay,
        Currency $currency,
        CategoryTemplate $template,
        array $amounts,
        ?int $reserveTarget,
        int $reservePct,
        string $surplusTarget,
        ?array $included = null,
        array $loanDetails = [],
        ?int $reserveFixed = null,
    ): void {
        DB::transaction(function () use ($user, $income, $periodMode, $paydayDay, $currency, $template, $amounts, $reserveTarget, $reservePct, $surplusTarget, $included, $loanDetails, $reserveFixed): void {
            $categories = $this->applyTemplate->handle($user, $template, $this->withoutEmptyFixedLines($template, $amounts, $included));

            foreach ($categories as $index => $category) {
                $user->budgetLines()->where('category_id', $category->id)->update(['amount' => max(0, $amounts[$index] ?? 0)]);

                $loanId = $user->budgetLines()->where('category_id', $category->id)->value('loan_id');

                if ($loanId !== null && ($amounts[$index] ?? 0) > 0) {
                    $user->loans()->whereKey($loanId)->update(['installment' => $amounts[$index]]);
                }

                if ($loanId !== null) {
                    $user->loans()->whereKey($loanId)->update(array_filter([
                        'principal_balance' => $loanDetails['principal'] ?? null,
                        'thm' => $loanDetails['thm'] ?? null,
                        'remaining_months' => $loanDetails['months'] ?? null,
                    ], fn (int|float|null $value): bool => $value !== null));
                }
            }

            $reserve = $user->pockets()->where('is_reserve', true)->first();

            if ($reserve === null && $reserveTarget !== null && $reserveTarget > 0) {
                $reserve = $user->pockets()->create(['name' => __('Reserve'), 'is_reserve' => true]);
            }

            $reserve?->update(['target_amount' => $reserveTarget]);

            $surplusPocketId = null;
            $surplusAccountId = null;

            if ($surplusTarget === 'investment') {
                $surplusAccountId = $user->accounts()->create([
                    'name' => __('Investment account'),
                    'type' => AccountType::Investment,
                    'currency' => $currency,
                ])->id;
            } else {
                $surplusPocketId = $user->pockets()->create(['name' => __('Savings')])->id;
            }

            $user->settings()->update([
                'income' => $income,
                'period_mode' => $periodMode,
                'payday_day' => $periodMode === PeriodMode::Payday ? $paydayDay : null,
                'currency' => $currency,
                'locale' => app()->getLocale(),
                'reserve_pct' => $reservePct,
                'reserve_fixed' => $reserveFixed,
                'surplus_pocket_id' => $surplusPocketId,
                'surplus_account_id' => $surplusAccountId,
                'onboarded_at' => now(),
                'onboarding_draft' => null,
            ]);

            $user->unsetRelation('budgetSetting');
            $this->periods->current($user);
        });
    }

    /**
     * @param  array<int, int>  $amounts
     * @param  list<int>|null  $included
     * @return list<int>
     */
    private function withoutEmptyFixedLines(CategoryTemplate $template, array $amounts, ?array $included): array
    {
        $indexes = $included ?? array_keys($template->items);

        return array_values(array_filter($indexes, function (int $index) use ($template, $amounts): bool {
            $item = $template->items[$index] ?? null;

            if ($item === null) {
                return false;
            }

            return ($amounts[$index] ?? 0) > 0
                || $item['type'] === LineType::Variable->value
                || ($item['pocket']['is_reserve'] ?? false);
        }));
    }
}
