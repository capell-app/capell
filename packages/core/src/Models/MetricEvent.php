<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Override;

/**
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property string $owner_package
 * @property string $collector_key
 * @property string $metric_key
 * @property string $definition_hash
 * @property string $scope_key
 * @property MetricScopeType $scope_type
 * @property int|null $site_id
 * @property string|null $site_uuid
 * @property string|null $language
 * @property string $timezone
 * @property string $day_starts_at
 * @property int $value
 * @property int $weight
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|MetricEvent newModelQuery()
 * @method static Builder<static>|MetricEvent newQuery()
 * @method static Builder<static>|MetricEvent query()
 */
final class MetricEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'occurred_at',
        'owner_package',
        'collector_key',
        'metric_key',
        'definition_hash',
        'scope_key',
        'scope_type',
        'site_id',
        'site_uuid',
        'language',
        'timezone',
        'day_starts_at',
        'value',
        'weight',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['weight' => 1];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (self $event): void {
            if (preg_match('/\A[a-f0-9]{64}\z/', $event->definition_hash) !== 1) {
                throw new InvalidArgumentException('Metric event definition hash must be SHA-256.');
            }

            if ($event->value < 1 || $event->weight < 1) {
                throw new InvalidArgumentException('Metric event value and weight must be positive integers.');
            }

            if ($event->occurred_at->utcOffset() !== 0) {
                throw new InvalidArgumentException('Metric event occurrence must be UTC.');
            }

            $scope = new MetricScopeData(
                type: $event->scope_type,
                timezone: $event->timezone,
                dayStartsAt: $event->day_starts_at,
                siteUuid: $event->site_uuid,
                language: $event->language,
            );

            if ($scope->key() !== $event->scope_key) {
                throw new InvalidArgumentException('Metric event scope key must be canonical.');
            }
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'scope_type' => MetricScopeType::class,
            'site_id' => 'integer',
            'value' => 'integer',
            'weight' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
