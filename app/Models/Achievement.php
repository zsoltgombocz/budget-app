<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A milestone reached ("Egy hét", "Első zárás"); celebrated_at is set once the celebration was shown.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property CarbonImmutable $achieved_at
 * @property CarbonImmutable|null $celebrated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'achieved_at', 'celebrated_at'])]
class Achievement extends Model
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
            'achieved_at' => 'immutable_datetime',
            'celebrated_at' => 'immutable_datetime',
        ];
    }
}
