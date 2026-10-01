<?php

namespace App\Actions\Budget;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReorderCategories
{
    /**
     * Move a category to a new position within its type group.
     */
    public function handle(User $user, int $categoryId, int $position): void
    {
        $category = $user->categories()->findOrFail($categoryId);

        $siblings = $user->categories()
            ->where('type', $category->type)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->reject(fn (Category $sibling): bool => $sibling->is($category))
            ->values();

        $siblings->splice(max(0, min($position, $siblings->count())), 0, [$category]);

        DB::transaction(function () use ($siblings): void {
            foreach ($siblings as $index => $sibling) {
                $sibling->update(['sort' => $index + 1]);
            }
        });
    }
}
