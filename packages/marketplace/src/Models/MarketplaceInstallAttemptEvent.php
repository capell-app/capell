<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $marketplace_install_attempt_id
 * @property MarketplaceInstallAttemptEventLevel $level
 * @property MarketplaceInstallFailureStage|null $stage
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property string|null $output_excerpt
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceInstallAttemptEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketplace_install_attempt_id',
        'level',
        'stage',
        'message',
        'context',
        'output_excerpt',
        'occurred_at',
    ];

    protected $table = 'marketplace_install_attempt_events';

    /** @return BelongsTo<MarketplaceInstallAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(MarketplaceInstallAttempt::class, 'marketplace_install_attempt_id');
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(fn (): bool => false);
        self::deleting(fn (): bool => false);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'level' => MarketplaceInstallAttemptEventLevel::class,
            'stage' => MarketplaceInstallFailureStage::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
