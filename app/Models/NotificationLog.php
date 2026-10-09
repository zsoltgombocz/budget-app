<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One push notification the app sent or decided not to send, with the push service's answer.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $notification_id
 * @property string $type
 * @property string $status
 * @property string|null $reason
 * @property int|null $push_status
 * @property string|null $push_host
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['notification_id', 'type', 'status', 'reason', 'push_status', 'push_host'])]
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use BelongsToUser, HasFactory, MassPrunable;

    public const string QUEUED = 'queued';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    /**
     * Kept for 30 days.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('created_at', '<', now()->subDays(30));
    }
}
