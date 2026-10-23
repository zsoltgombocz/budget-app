<?php

namespace App\Models;

use App\Enums\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a user's plan: who set what, until when. A null plan means back to automatic
 * (trial, then Alap).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $admin_id
 * @property Plan|null $plan
 * @property CarbonImmutable|null $plan_until
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'admin_id', 'plan', 'plan_until', 'note'])]
class PlanChange extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
            'plan_until' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
