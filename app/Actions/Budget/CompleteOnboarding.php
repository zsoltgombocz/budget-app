<?php

namespace App\Actions\Budget;

use App\Enums\AccountType;
use App\Enums\Currency;
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
     * @param  array<int, int>  $amounts  template item index => planned amount
     * @param  'investment'|'pocket'  $surplusTarget
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
    ): void {
        DB::transaction(function () use ($user, $income, $periodMode, $paydayDay, $currency, $template, $amounts, $reserveTarget, $reservePct, $surplusTarget): void {
            $categories = $this->applyTemplate->handle($user, $template);

            foreach ($categories as $index => $category) {
                $user->budgetLines()->where('category_id', $category->id)->update(['amount' => max(0, $amounts[$index] ?? 0)]);
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
                'surplus_pocket_id' => $surplusPocketId,
                'surplus_account_id' => $surplusAccountId,
                'onboarded_at' => now(),
            ]);

            $user->unsetRelation('budgetSetting');
            $this->periods->current($user);
        });
    }
}
