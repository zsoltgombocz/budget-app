<?php

namespace App\Actions\Budget;

use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Enums\PrepayMode;
use App\Models\Category;
use App\Models\CategoryTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApplyCategoryTemplate
{
    /**
     * Create the template's categories with an empty plan line each, plus the pockets
     * and loan placeholders they need. Names are translated into the current locale.
     *
     * @return list<Category>
     */
    public function handle(User $user, CategoryTemplate $template): array
    {
        return DB::transaction(function () use ($user, $template): array {
            $sort = $user->categories()->withTrashed()->count();
            $categories = [];

            foreach ($template->items as $item) {
                $type = LineType::from($item['type']);
                $name = __($item['name']);

                $category = $user->categories()->create([
                    'name' => $name,
                    'type' => $type,
                    'icon' => $item['icon'] ?? null,
                    'color' => $item['color'] ?? null,
                    'sort' => ++$sort,
                    'is_quick_entry' => $item['is_quick_entry'] ?? $type === LineType::Variable,
                ]);

                $pocketId = null;
                $loanId = null;

                if (isset($item['pocket'])) {
                    $pocketId = $user->pockets()->create([
                        'name' => __($item['pocket']['name']),
                        'is_reserve' => $item['pocket']['is_reserve'] ?? false,
                        'is_shared' => $item['pocket']['is_shared'] ?? false,
                        'prepay_step' => $item['pocket']['prepay_step'] ?? null,
                        'sort' => $sort,
                    ])->id;
                }

                if ($item['loan'] ?? false) {
                    $loanId = $user->loans()->create([
                        'name' => $name,
                        'principal_balance' => 0,
                        'installment' => 0,
                        'prepay_mode' => PrepayMode::ReduceInstallment,
                    ])->id;
                }

                $user->budgetLines()->create([
                    'category_id' => $category->id,
                    'amount' => $item['amount'] ?? 0,
                    'calc_mode' => CalcMode::Fixed,
                    'pocket_id' => $pocketId,
                    'loan_id' => $loanId,
                ]);

                $categories[] = $category;
            }

            $this->linkPrepaymentPocketToLoan($user);

            return $categories;
        });
    }

    /**
     * A prepayment pocket created next to a single loan saves up for that loan.
     */
    private function linkPrepaymentPocketToLoan(User $user): void
    {
        $loans = $user->loans()->pluck('id');

        if ($loans->count() !== 1) {
            return;
        }

        $user->pockets()
            ->whereNotNull('prepay_step')
            ->whereNull('loan_id')
            ->update(['loan_id' => $loans->first()]);
    }
}
