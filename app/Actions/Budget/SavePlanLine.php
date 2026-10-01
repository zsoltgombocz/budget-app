<?php

namespace App\Actions\Budget;

use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SavePlanLine
{
    /**
     * Create or update a plan line together with its category.
     *
     * @param  array{name: string, type: LineType, icon?: string|null, color?: string|null, is_quick_entry?: bool, amount: int, amount_avg?: int|null, amount_max?: int|null, calc_mode?: CalcMode, due_day?: int|null, pocket_id?: int|null, loan_id?: int|null, orig_amount?: int|null, orig_currency?: string|null, active_from?: string|null, active_to?: string|null, note?: string|null}  $data
     */
    public function handle(User $user, array $data, ?BudgetLine $line = null): BudgetLine
    {
        return DB::transaction(function () use ($user, $data, $line): BudgetLine {
            $type = $data['type'];
            $calcMode = $type === LineType::Variable ? ($data['calc_mode'] ?? CalcMode::Fixed) : CalcMode::Fixed;

            $categoryData = [
                'name' => $data['name'],
                'type' => $type,
                'icon' => $data['icon'] ?? null,
                'color' => $data['color'] ?? null,
                'is_quick_entry' => $type === LineType::Variable && ($data['is_quick_entry'] ?? true),
            ];

            if ($line instanceof BudgetLine && $line->category !== null) {
                $line->category->update($categoryData);
                $category = $line->category;
            } else {
                $category = $user->categories()->create([
                    ...$categoryData,
                    'sort' => $user->categories()->withTrashed()->count() + 1,
                ]);
            }

            $lineData = [
                'category_id' => $category->id,
                'amount' => $data['amount'],
                'amount_avg' => $type === LineType::Variable ? ($data['amount_avg'] ?? null) : null,
                'amount_max' => $type === LineType::Variable ? ($data['amount_max'] ?? null) : null,
                'calc_mode' => $calcMode,
                'due_day' => $type === LineType::Variable ? null : ($data['due_day'] ?? null),
                'pocket_id' => in_array($type, [LineType::Transfer, LineType::Sinking], true) ? ($data['pocket_id'] ?? null) : null,
                'loan_id' => $type === LineType::Loan ? ($data['loan_id'] ?? null) : null,
                'orig_amount' => $data['orig_amount'] ?? null,
                'orig_currency' => $data['orig_currency'] ?? null,
                'active_from' => $data['active_from'] ?? null,
                'active_to' => $data['active_to'] ?? null,
                'note' => $data['note'] ?? null,
            ];

            if ($line instanceof BudgetLine) {
                $line->update($lineData);

                return $line;
            }

            return $user->budgetLines()->create($lineData);
        });
    }
}
