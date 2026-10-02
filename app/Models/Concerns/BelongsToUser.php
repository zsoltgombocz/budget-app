<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Scopes a model to the authenticated user and fills user_id on create.
 *
 * Outside an app user's context (queues, scheduler, seeders, the admin panel, whose
 * guard holds an Admin) no scope is applied, so that code must query through the
 * user's relations.
 *
 * @mixin Model
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::user() instanceof User) {
                $builder->where($builder->qualifyColumn('user_id'), Auth::id());
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('user_id') === null && Auth::user() instanceof User) {
                $model->setAttribute('user_id', Auth::id());
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
