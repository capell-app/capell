<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property string $composer_name
 * @property string $extension_slug
 * @property string $extension_name
 * @property string $kind
 * @property MarketplaceInstallIntentStatus $status
 * @property string|null $composer_command
 * @property string|null $version_constraint
 * @property array<string, mixed>|null $requested_options
 * @property array<string, mixed>|null $eligibility
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $diagnostic_context
 * @property array<string, mixed>|null $deployment
 * @property string|null $failure_reason
 * @property string|null $failure_type
 * @property string|null $failure_stage
 * @property int|null $retry_of_id
 * @property int|string|null $retried_by_id
 * @property CarbonImmutable|null $retried_at
 * @property string|null $telemetry_status
 * @property CarbonImmutable|null $telemetry_attempted_at
 * @property CarbonImmutable|null $telemetry_synced_at
 * @property string|null $telemetry_failure
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $output_excerpt
 * @property string|null $error_excerpt
 * @property int|string|null $user_id
 * @property string|null $user_email
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceInstallAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'composer_name',
        'extension_slug',
        'extension_name',
        'kind',
        'status',
        'composer_command',
        'version_constraint',
        'requested_options',
        'eligibility',
        'context',
        'diagnostic_context',
        'deployment',
        'failure_reason',
        'failure_type',
        'failure_stage',
        'retry_of_id',
        'retried_by_id',
        'retried_at',
        'telemetry_status',
        'telemetry_attempted_at',
        'telemetry_synced_at',
        'telemetry_failure',
        'queued_at',
        'started_at',
        'completed_at',
        'cancel_requested_at',
        'cancelled_at',
        'output_excerpt',
        'error_excerpt',
        'user_id',
        'user_email',
        'resolved_at',
    ];

    protected $table = 'marketplace_install_attempts';

    /** @return HasMany<MarketplaceInstallAttemptEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceInstallAttemptEvent::class)->oldest('occurred_at')->orderBy('id');
    }

    /** @return BelongsTo<MarketplaceInstallAttempt, $this> */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /** @return HasMany<MarketplaceInstallAttempt, $this> */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => MarketplaceInstallIntentStatus::class,
            'requested_options' => 'array',
            'eligibility' => 'array',
            'context' => 'array',
            'diagnostic_context' => 'array',
            'deployment' => 'array',
            'resolved_at' => 'immutable_datetime',
            'retried_at' => 'immutable_datetime',
            'telemetry_attempted_at' => 'immutable_datetime',
            'telemetry_synced_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
