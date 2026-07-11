<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $notice_id
 * @property CarbonImmutable|null $dismissed_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UpdateNoticeDismissal extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'notice_id',
        'dismissed_until',
    ];

    protected $table = 'marketplace_update_notice_dismissals';

    #[Override]
    protected function casts(): array
    {
        return [
            'dismissed_until' => 'immutable_datetime',
        ];
    }
}
