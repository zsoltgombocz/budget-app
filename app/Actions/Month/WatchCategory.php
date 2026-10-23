<?php

namespace App\Actions\Month;

use App\Enums\LineType;
use App\Models\User;
use App\Models\WatchedCategory;
use App\Services\Watching;
use Illuminate\Validation\ValidationException;

/**
 * "Kategória figyelése": a monthly amount on a spending category, only watched.
 */
final readonly class WatchCategory
{
    public function __construct(private Watching $watching) {}

    public function watch(User $user, int $categoryId, int $amount): WatchedCategory
    {
        $category = $user->categories()->where('type', LineType::Variable)->findOrFail($categoryId);

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount.')]);
        }

        $existing = $user->watchedCategories()->where('category_id', $category->id)->first();

        if (! $existing instanceof WatchedCategory && ! $this->watching->canWatchMore($user->watchedCategories()->count(), $user->hasPlusz())) {
            throw ValidationException::withMessages(['category' => __('The base plan watches two categories.')]);
        }

        return $user->watchedCategories()->updateOrCreate(['category_id' => $category->id], ['amount' => $amount]);
    }

    public function unwatch(User $user, int $categoryId): void
    {
        $user->watchedCategories()->where('category_id', $categoryId)->delete();
    }
}
