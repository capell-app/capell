<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Override;

/**
 * @property int $id
 * @property CarbonImmutable $day
 * @property string $owner_package
 * @property string $collector_key
 * @property string $definition_hash
 * @property MetricCollectionRunStatus $status
 * @property string|null $source_watermark
 * @property string|null $source_checksum
 * @property string|null $error_summary
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|MetricCollectionRun newModelQuery()
 * @method static Builder<static>|MetricCollectionRun newQuery()
 * @method static Builder<static>|MetricCollectionRun query()
 */
final class MetricCollectionRun extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'day',
        'owner_package',
        'collector_key',
        'definition_hash',
        'status',
        'source_watermark',
        'source_checksum',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'started',
    ];

    /** @return HasMany<MetricDailyRollup, $this> */
    public function rollups(): HasMany
    {
        return $this->hasMany(MetricDailyRollup::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (self $run): void {
            $rawStatus = $run->getAttributes()['status'] ?? null;
            $status = $rawStatus instanceof MetricCollectionRunStatus
                ? $rawStatus
                : MetricCollectionRunStatus::tryFrom((string) $rawStatus);

            throw_if($status === null
                || preg_match('/\A[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\z/', $run->owner_package) !== 1
                || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $run->collector_key) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $run->definition_hash) !== 1
                || ($run->source_checksum !== null && preg_match('/\A[a-f0-9]{64}\z/', $run->source_checksum) !== 1), InvalidArgumentException::class, 'Metric collection run identity and checksum must be valid.');

            $hasCompletion = $run->completed_at !== null;
            $hasError = trim((string) $run->error_summary) !== '';
            $hasWatermark = trim((string) $run->source_watermark) !== '';
            $hasSource = $hasWatermark && $run->source_checksum !== null;

            throw_if($run->error_summary !== null && mb_strlen($run->error_summary) > 1000, InvalidArgumentException::class, 'Metric collection run error summaries may not exceed 1000 characters.');

            throw_if(($status === MetricCollectionRunStatus::Started && ($hasCompletion || $hasError || $hasWatermark || $run->source_checksum !== null))
                || ($status === MetricCollectionRunStatus::Completed && (! $hasCompletion || ! $hasSource || $hasError))
                || ($status === MetricCollectionRunStatus::Failed && (! $hasCompletion || ! $hasError || $run->source_checksum !== null))
                || ($status === MetricCollectionRunStatus::Unsupported && (! $hasCompletion || ! $hasError || $hasWatermark || $run->source_checksum !== null)), InvalidArgumentException::class, 'Metric collection run fields do not match its lifecycle state.');

            throw_if($run->completed_at !== null && $run->completed_at->isBefore($run->started_at), InvalidArgumentException::class, 'Metric collection runs cannot complete before they start.');
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'status' => MetricCollectionRunStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
