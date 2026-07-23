<?php

declare(strict_types=1);

use Capell\Core\Actions\Metrics\StoreMetricCollectionRunAction;
use Capell\Core\Actions\Metrics\StoreMetricDailyRollupAction;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates portable rollup storage with non-null scope identity', function (): void {
    expect(Schema::hasColumns('metric_daily_rollups', [
        'metric_collection_run_id', 'day', 'owner_package', 'collector_key', 'metric_key',
        'definition_hash', 'scope_key', 'scope_type', 'site_id', 'site_uuid', 'language',
        'timezone', 'day_starts_at', 'unit', 'value_type', 'value', 'scale', 'currency', 'point_state',
    ]))->toBeTrue();

    expect(fn (): MetricDailyRollup => MetricDailyRollup::query()->create([
        ...validStoredRollupAttributes(),
        'scope_key' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('enforces daily identity for global site and site-language scopes', function (MetricScopeData $scope): void {
    $rollup = storeMetricRollup(scope: $scope, definition: storageMetricDefinition(scopeType: $scope->type));

    expect($rollup->scope_key)->toBe($scope->key());

    expect(fn (): MetricDailyRollup => storeMetricRollup(scope: $scope, definition: storageMetricDefinition(scopeType: $scope->type)))
        ->toThrow(QueryException::class);
})->with([
    'global' => [fn (): MetricScopeData => MetricScopeData::global('UTC')],
    'site' => [fn (): MetricScopeData => MetricScopeData::site('018f0f21-c72b-7c29-8471-18f58db0be27', 'Europe/London')],
    'site language' => [fn (): MetricScopeData => MetricScopeData::siteLanguage('018f0f21-c72b-7c29-8471-18f58db0be27', 'en-GB', 'Europe/London')],
]);

it('freezes currency in the definition instead of metric identity', function (): void {
    $gbp = storageMetricDefinition(
        representation: new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'GBP'),
    );

    storeMetricRollup(definition: $gbp, value: MetricValueData::money(1250, 'GBP', 2));

    expect(fn (): MetricDailyRollup => storeMetricRollup(
        definition: $gbp,
        value: MetricValueData::money(1300, 'USD', 2),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn (): MetricDailyRollup => storeMetricRollup(
        definition: storageMetricDefinition(
            representation: new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'USD'),
        ),
        value: MetricValueData::money(1300, 'USD', 2),
    ))->toThrow(QueryException::class);
});

it('enforces explicit point-state value invariants', function (MetricPointState $state, ?MetricValueData $value, bool $valid): void {
    $store = fn (): MetricDailyRollup => storeMetricRollup(state: $state, value: $value);

    if ($valid) {
        expect($store()->point_state)->toBe($state);

        return;
    }

    expect($store)->toThrow(InvalidArgumentException::class);
})->with([
    'present' => [MetricPointState::Present, MetricValueData::integer(42), true],
    'zero' => [MetricPointState::Zero, MetricValueData::integer(0), true],
    'zero with non-zero value' => [MetricPointState::Zero, MetricValueData::integer(1), false],
    'present with zero value' => [MetricPointState::Present, MetricValueData::integer(0), false],
    'missing' => [MetricPointState::Missing, null, true],
    'missing with value' => [MetricPointState::Missing, MetricValueData::integer(1), false],
    'stale' => [MetricPointState::Stale, null, true],
    'unsupported' => [MetricPointState::Unsupported, null, true],
]);

it('blocks direct writes that bypass canonical scope and run ownership invariants', function (): void {
    expect(fn (): MetricDailyRollup => MetricDailyRollup::query()->create([
        ...validStoredRollupAttributes(),
        'scope_key' => 'global:UTC:01:00:00',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn (): MetricDailyRollup => MetricDailyRollup::query()->create([
        ...validStoredRollupAttributes(),
        'owner_package' => 'vendor/other',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn (): MetricDailyRollup => MetricDailyRollup::query()->create([
        ...validStoredRollupAttributes(),
        'point_state' => 'unknown',
    ]))->toThrow(ValueError::class);
});

it('retains portable scope identity after its local site is deleted', function (): void {
    $site = Site::factory()->create();
    $scope = MetricScopeData::site('018f0f21-c72b-7c29-8471-18f58db0be27', 'UTC');
    $rollup = storeMetricRollup(
        scope: $scope,
        definition: storageMetricDefinition(scopeType: MetricScopeType::Site),
        value: MetricValueData::integer(42),
        siteId: $site->getKey(),
    );

    DB::table($site->getTable())->where('id', $site->getKey())->delete();
    $rollup->refresh();

    expect($rollup->site_id)->toBeNull()
        ->and($rollup->site_uuid)->toBe($scope->siteUuid)
        ->and($rollup->scope_key)->toBe($scope->key());
});

function storageMetricDefinition(
    MetricScopeType $scopeType = MetricScopeType::Global,
    ?MetricRepresentationData $representation = null,
): MetricDefinitionData {
    return new MetricDefinitionData(
        identity: new MetricIdentityData('capell-app/site-stats', 'content_totals', 'published_pages'),
        representation: $representation ?? new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        scopeType: $scopeType,
        semantics: new MetricSemanticsData(MetricSemantic::Counter, MetricAggregation::Sum, MetricGapPolicy::Missing, MetricBackfillPolicy::Supported),
        governance: new MetricGovernanceData(MetricSource::Database, 'content.published-pages', MetricSensitivity::Internal, MetricVisibility::SiteAdmin),
    );
}

function storageMetricRun(): MetricCollectionRun
{
    return resolve(StoreMetricCollectionRunAction::class)->execute(
        day: '2026-07-21',
        ownerPackage: 'capell-app/site-stats',
        collectorKey: 'content_totals',
        definitionHash: str_repeat('a', 64),
        status: MetricCollectionRunStatus::Started,
        startedAt: CarbonImmutable::parse('2026-07-22 00:05:00'),
    );
}

function storeMetricRollup(
    ?MetricDefinitionData $definition = null,
    ?MetricScopeData $scope = null,
    MetricPointState $state = MetricPointState::Present,
    ?MetricValueData $value = null,
    ?int $siteId = null,
): MetricDailyRollup {
    return resolve(StoreMetricDailyRollupAction::class)->execute(
        run: storageMetricRun(),
        definition: $definition ?? storageMetricDefinition(),
        day: '2026-07-21',
        scope: $scope ?? MetricScopeData::global('UTC'),
        state: $state,
        value: func_num_args() >= 4 ? $value : MetricValueData::integer(42),
        siteId: $siteId,
    );
}

/** @return array<string, mixed> */
function validStoredRollupAttributes(): array
{
    $run = storageMetricRun();
    $definition = storageMetricDefinition();

    return [
        'metric_collection_run_id' => $run->getKey(), 'day' => '2026-07-21',
        'owner_package' => 'capell-app/site-stats', 'collector_key' => 'content_totals',
        'metric_key' => 'published_pages', 'definition_hash' => $definition->semanticHash(),
        'scope_key' => 'global:UTC:00:00:00', 'scope_type' => MetricScopeType::Global,
        'site_id' => null, 'site_uuid' => null, 'language' => null, 'timezone' => 'UTC',
        'day_starts_at' => '00:00:00', 'unit' => MetricUnitEnum::Count,
        'value_type' => MetricValueType::Integer, 'value' => '42', 'scale' => null,
        'currency' => '', 'point_state' => MetricPointState::Present,
    ];
}
