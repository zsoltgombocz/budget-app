<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\WatchedCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spending category the user keeps an eye on with a monthly amount. It is only watched:
 * the month's money and the daily allowance do not change because of it.
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property int $amount
 * @property bool $kept_on_basic stays visible on the base plan (picked when going back from Plusz)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['category_id', 'amount', 'kept_on_basic'])]
class WatchedCategory extends Model
{
    /** @use HasFactory<WatchedCategoryFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'kept_on_basic' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }
}
