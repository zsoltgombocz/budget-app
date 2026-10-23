<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\ExtraIncomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that came in on top of the month's income ("Kaptam pénzt"); it adds to the month's money at once.
 *
 * @property int $id
 * @property int $user_id
 * @property int $period_id
 * @property int $amount
 * @property CarbonImmutable $occurred_on
 * @property string|null $note
 * @property string|null $client_uuid
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['period_id', 'amount', 'occurred_on', 'note', 'client_uuid'])]
class ExtraIncome extends Model
{
    /** @use HasFactory<ExtraIncomeFactory> */
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
            'occurred_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
