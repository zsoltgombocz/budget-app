<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One of Morzsa's things the user bought with acorns; equipped ones are worn or in the room.
 *
 * @property int $id
 * @property int $user_id
 * @property string $item
 * @property bool $equipped
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['item', 'equipped'])]
class UserItem extends Model
{
    use BelongsToUser;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'equipped' => 'boolean',
        ];
    }
}
