<?php

namespace App\Services;

use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves one user's 1.x plan to the 2.0 model.
 *
 * - Transfer lines become plain fixed expenses, paid by hand ("Kézzel fizetem").
 * - Sinking lines stay: they are the 2.0 savings lines ("Félretétel"), marked as paid by hand so the
 *   due day brings a "Félretettem" button.
 * - Variable lines leave the plan: each becomes a watched category with the same monthly amount
 *   (its average/maximum when the line used one), so the month's money is income − fixed lines.
 * - The leftover rule (reserve share, surplus pocket/account) is cleared; closing asks instead.
 *
 * Pockets, loans, past months, spending and day marks are kept as they are.
 */
final class V2Conversion
{
    /**
     * A dry run writes nothing and only reports.
     *
     * @return list<string> what was (or, on a dry run, would be) changed, one line per change
     */
    public function convert(User $user, bool $dryRun = true): array
    {
        $changes = [];

        DB::transaction(function () use ($user, $dryRun, &$changes): void {
            $lines = BudgetLine::query()->withoutGlobalScopes()->where('user_id', $user->id)->with('category')->get();

            foreach ($lines as $line) {
                $category = $line->category;

                if (! $category instanceof Category) {
                    continue;
                }

                match ($category->type) {
                    LineType::Transfer => $changes[] = $this->transferToFixed($line, $category, $dryRun),
                    LineType::Sinking => $changes[] = $this->markSavings($line, $category, $dryRun),
                    LineType::Variable => $changes[] = $this->variableToWatched($user, $line, $category, $dryRun),
                    default => null,
                };
            }

            $settings = $user->settings();

            if ($settings->surplus_pocket_id !== null || $settings->surplus_account_id !== null || $settings->reserve_fixed !== null || $settings->reserve_pct !== 100) {
                $changes[] = 'Leftover rule cleared: closing asks what happens to the leftover.';

                if (! $dryRun) {
                    $settings->update(['reserve_pct' => 100, 'reserve_fixed' => null, 'surplus_pocket_id' => null, 'surplus_account_id' => null]);
                }
            }
        });

        return $changes;
    }

    private function transferToFixed(BudgetLine $line, Category $category, bool $dryRun): string
    {
        if (! $dryRun) {
            $category->update(['type' => LineType::Fixed]);
            $line->update(['manual_pay' => true]);
        }

        return "Transfer \"{$category->name}\" ({$line->amount}) becomes a fixed expense paid by hand.";
    }

    private function markSavings(BudgetLine $line, Category $category, bool $dryRun): string
    {
        if (! $dryRun) {
            $line->update(['manual_pay' => true]);
        }

        return "Savings \"{$category->name}\" ({$line->amount}) stays a savings line with a \"Félretettem\" button.";
    }

    private function variableToWatched(User $user, BudgetLine $line, Category $category, bool $dryRun): string
    {
        $amount = match ($line->calc_mode) {
            CalcMode::Avg => $line->amount_avg ?? $line->amount,
            CalcMode::Max => $line->amount_max ?? $line->amount,
            CalcMode::Fixed => $line->amount,
        };

        if (! $dryRun) {
            if ($amount > 0) {
                $user->watchedCategories()->withoutGlobalScopes()->updateOrCreate(
                    ['category_id' => $category->id],
                    ['amount' => $amount],
                );
            }

            $line->delete();
        }

        return $amount > 0
            ? "Budget \"{$category->name}\" ({$amount}) leaves the plan and becomes a watched category."
            : "Empty budget \"{$category->name}\" leaves the plan.";
    }
}
