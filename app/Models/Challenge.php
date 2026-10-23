<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The weekly challenge, optional: offered on Monday, taken or declined, done when reached.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $week_start
 * @property string $kind
 * @property int $target
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $declined_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['week_start', 'kind', 'target', 'accepted_at', 'declined_at', 'completed_at'])]
class Challenge extends Model
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
            'week_start' => 'immutable_date',
            'target' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
