<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\CaptureTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A revocable key for the automatic capture endpoint. Only its SHA-256 hash is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'token_hash', 'last_used_at'])]
#[Hidden(['token_hash'])]
class CaptureToken extends Model
{
    /** @use HasFactory<CaptureTokenFactory> */
    use BelongsToUser, HasFactory;

    public const string PREFIX = 'msc_';

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
        ];
    }
}
