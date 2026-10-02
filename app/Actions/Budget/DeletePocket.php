<?php

namespace App\Actions\Budget;

use App\Enums\LineType;
use App\Models\Pocket;
use Illuminate\Support\Facades\DB;

/**
 * Delete a pocket together with the plan lines that only saved into it, so no monthly
 * "put aside" stays in the plan without a pocket to go to. Other lines that pointed at the
 * pocket (a transfer, for example) stay and simply lose the link.
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

            $pocket->delete();
        });
    }
}
