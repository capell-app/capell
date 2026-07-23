<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Override;

/**
 * @property int $id
 * @property int $metric_collection_run_id
 * @property CarbonImmutable $day
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
 * @property MetricUnitEnum $unit
 * @property MetricValueType $value_type
 * @property string|null $value
 * @property int|null $scale
 * @property string $currency
 * @property MetricPointState $point_state
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|MetricDailyRollup newModelQuery()
 * @method static Builder<static>|MetricDailyRollup newQuery()
 * @method static Builder<static>|MetricDailyRollup query()
 */
final class MetricDailyRollup extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'metric_collection_run_id',
        'day',
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
        'unit',
        'value_type',
        'value',
        'scale',
        'currency',
        'point_state',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => '',
        'point_state' => 'present',
    ];

    /** @return BelongsTo<MetricCollectionRun, $this> */
    public function collectionRun(): BelongsTo
    {
        return $this->belongsTo(MetricCollectionRun::class, 'metric_collection_run_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (self $rollup): void {
            throw_if(preg_match('/\A[a-f0-9]{64}\z/', $rollup->definition_hash) !== 1, InvalidArgumentException::class, 'Metric rollup definition hash must be SHA-256.');

            $scopeType = $rollup->scope_type instanceof MetricScopeType
                ? $rollup->scope_type
                : MetricScopeType::from((string) $rollup->scope_type);
            $scope = new MetricScopeData(
                type: $scopeType,
                timezone: $rollup->timezone,
                dayStartsAt: $rollup->day_starts_at,
                siteUuid: $rollup->site_uuid,
                language: $rollup->language,
            );

            throw_if($scope->key() !== $rollup->scope_key, InvalidArgumentException::class, 'Metric rollup scope key must be canonical.');

            $valueType = $rollup->value_type instanceof MetricValueType
                ? $rollup->value_type
                : MetricValueType::from((string) $rollup->value_type);
            $unit = $rollup->unit instanceof MetricUnitEnum
                ? $rollup->unit
                : MetricUnitEnum::from((string) $rollup->unit);
            $representation = new MetricRepresentationData(
                unit: $unit,
                valueType: $valueType,
                scale: $rollup->scale,
                currency: $rollup->currency === '' ? null : $rollup->currency,
            );
            $rawState = $rollup->getAttributes()['point_state'] ?? null;
            $state = $rawState instanceof MetricPointState
                ? $rawState
                : MetricPointState::tryFrom((string) $rawState);

            throw_if($state === null, InvalidArgumentException::class, 'Metric rollup point state must be supported.');

            self::assertStoredValue($rollup, $representation, $state);

            $run = $rollup->collectionRun()->first();

            throw_if($run === null
                || $run->day->toDateString() !== $rollup->day->toDateString()
                || $run->owner_package !== $rollup->owner_package
                || $run->collector_key !== $rollup->collector_key, InvalidArgumentException::class, 'Metric rollup identity must match its collection run.');
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'scope_type' => MetricScopeType::class,
            'unit' => MetricUnitEnum::class,
            'value_type' => MetricValueType::class,
            'point_state' => MetricPointState::class,
            'scale' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    private static function assertStoredValue(
        self $rollup,
        MetricRepresentationData $representation,
        MetricPointState $state,
    ): void {
        if ($rollup->value === null) {
            throw_unless(in_array($state, [MetricPointState::Missing, MetricPointState::Stale, MetricPointState::Unsupported], true), InvalidArgumentException::class, 'Present and zero metric points require a value.');

            return;
        }

        throw_if(in_array($state, [MetricPointState::Missing, MetricPointState::Stale, MetricPointState::Unsupported], true), InvalidArgumentException::class, 'Non-value metric point states cannot persist a value.');

        $value = match ($representation->valueType) {
            MetricValueType::Integer => MetricValueData::integer(self::canonicalInteger($rollup->value)),
            MetricValueType::Decimal => MetricValueData::decimal($rollup->value, $representation->scale ?? 0),
            MetricValueType::MinorCurrencyUnit => MetricValueData::money(
                self::canonicalInteger($rollup->value),
                $representation->currency ?? '',
                $representation->scale ?? 0,
            ),
        };
        $value->assertMatches($representation);

        $isZero = ($value->integer ?? $value->minorUnits) === 0
            || ($value->decimal !== null && preg_match('/\A0(?:\.0+)?\z/', $value->decimal) === 1);

        throw_if(($state === MetricPointState::Zero) !== $isZero, InvalidArgumentException::class, 'Metric zero state must agree with its value.');
    }

    private static function canonicalInteger(string $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        throw_if($integer === false || (string) $integer !== $value, InvalidArgumentException::class, 'Metric integer values must be canonical and in range.');

        return $integer;
    }
}
