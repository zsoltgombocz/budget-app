<?php

namespace App\Models;

use App\Enums\PocketMovementType;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\PocketMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $pocket_id
 * @property int|null $period_id
 * @property int $amount
 * @property PocketMovementType $type
 * @property CarbonImmutable $occurred_on
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pocket_id', 'period_id', 'amount', 'type', 'occurred_on', 'note'])]
class PocketMovement extends Model
{
    /** @use HasFactory<PocketMovementFactory> */
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
            'type' => PocketMovementType::class,
            'occurred_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Pocket, $this>
     */
    public function pocket(): BelongsTo
    {
        return $this->belongsTo(Pocket::class);
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
