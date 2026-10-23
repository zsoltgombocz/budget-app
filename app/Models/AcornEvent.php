<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One acorn award (positive) or purchase (negative); (reason, ref) is unique per user, so an award happens once.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property string $reason
 * @property string $ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['amount', 'reason', 'ref'])]
class AcornEvent extends Model
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
            'amount' => 'integer',
        ];
    }
}
