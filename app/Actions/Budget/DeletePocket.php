<?php

namespace App\Actions\Budget;

use App\Enums\LineType;
use App\Models\BudgetSetting;
use App\Models\Pocket;
use Illuminate\Support\Facades\DB;

/**
 * "Delete" a pocket by archiving it (soft delete): it leaves the pockets list, the pickers, the
 * plan and closing, but its movements, its balance and the spending it paid for stay as they
 * were, so past periods do not change.
 *
 * The plan lines that only saved into it are deleted, so no monthly "put aside" stays in the
 * plan without a pocket to go to. Other lines that pointed at the pocket (a transfer, for
 * example) stay and simply lose the link, and the leftover no longer goes to it at closing.
 */
final readonly class DeletePocket
{
    public function __construct(private DeletePlanLine $deletePlanLine) {}

    public function handle(Pocket $pocket): void
    {
        DB::transaction(function () use ($pocket): void {
            foreach ($pocket->budgetLines()->with('category')->get() as $line) {
                if ($line->category?->type === LineType::Sinking) {
                    $this->deletePlanLine->handle($line);
                } else {
                    $line->update(['pocket_id' => null]);
                }
            }

            BudgetSetting::query()->withoutGlobalScopes()
                ->where('user_id', $pocket->user_id)
                ->where('surplus_pocket_id', $pocket->id)
                ->update(['surplus_pocket_id' => null]);

            $pocket->delete();
        });
    }
}
