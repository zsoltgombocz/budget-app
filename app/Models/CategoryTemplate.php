<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * A starting set of categories offered in onboarding. Global, not user scoped.
 * @property array<int, array{name: string, type: string, icon?: string|null, color?: string|null, is_quick_entry?: bool, amount?: int, pocket?: array{name: string, is_reserve?: bool, is_shared?: bool, prepay_step?: int|null}, loan?: bool}> $items
 */
#[Fillable(['key', 'name', 'description', 'items', 'sort'])]
class CategoryTemplate extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'sort' => 'integer',
        ];
    }
}
