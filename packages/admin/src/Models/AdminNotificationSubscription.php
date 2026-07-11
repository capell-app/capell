<?php

declare(strict_types=1);

namespace Capell\Admin\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * @property int $id
 * @property string $user_type
 * @property int|string $user_id
 * @property string $group_key
 * @property bool $subscribed
 */
final class AdminNotificationSubscription extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_admin_notification_subscriptions';

    protected $fillable = [
        'user_type',
        'user_id',
        'group_key',
        'subscribed',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'subscribed' => 'boolean',
        ];
    }
}
