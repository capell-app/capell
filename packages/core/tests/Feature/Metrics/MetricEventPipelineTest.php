<?php

declare(strict_types=1);

use Capell\Core\Actions\Metrics\RollupMetricEventsAction;
use Capell\Core\Actions\Metrics\StoreMetricEventAction;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Facades\Metrics;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\MetricEvent;
use Capell\Core\Support\Metrics\MetricEventRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Lottery;

it('registers only globally unique active integer count event definitions', function (): void {
    $registry = new MetricEventRegistry;
    $definition = metricEventPipelineDefinition();

    expect($registry->register($definition)->register($definition)->definition('requests'))->toBe($definition)
        ->and($registry->definitions())->toHaveCount(1);

    expect(fn (): MetricEventRegistry => $registry->register(metricEventPipelineDefinition(owner: 'vendor/other')))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): MetricEventRegistry => $registry->register(metricEventPipelineDefinition(semantic: MetricSemantic::Counter)))
        ->toThrow(InvalidArgumentException::class);
});

it('persists sampled facade events after the request with their sampling weight', function (): void {
    Lottery::alwaysWin();
    Metrics::register(metricEventPipelineDefinition());
    $this->withoutDefer();

    Metrics::record(
        metric: 'requests',
        value: 3,
        sampleEvery: 10,
        occurredAt: CarbonImmutable::parse('2026-07-21 12:00:00', 'Europe/London'),
    );

    $event = MetricEvent::query()->sole();

    expect($event->value)->toBe(3)
        ->and($event->weight)->toBe(10)
        ->and($event->occurred_at->utcOffset())->toBe(0);
});

it('never lets invalid calls or unavailable event storage escape the request path', function (): void {
    $log = Log::spy();
    Metrics::record('missing', 0, 0);
    Metrics::register(metricEventPipelineDefinition());
    $this->withoutDefer();
    Schema::drop('metric_events');

    try {
        Metrics::increment('requests');
    } finally {
        $migration = require __DIR__ . '/../../../database/migrations/2026_07_22_000003_create_metric_events_table.php';
        $migration->up();
    }

    $log->shouldHaveReceived('warning')->atLeast()->once();
});

it('stores only canonical positive UTC integer count events', function (): void {
    $action = resolve(StoreMetricEventAction::class);
    $definition = metricEventPipelineDefinition();

    expect(fn (): MetricEvent => $action->execute(
        $definition,
        0,
        1,
        MetricScopeData::global('UTC'),
        CarbonImmutable::now('UTC'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn (): MetricEvent => $action->execute(
        $definition,
        1,
        1,
        MetricScopeData::global('UTC'),
        CarbonImmutable::now('Europe/London'),
    ))->toThrow(InvalidArgumentException::class);
});

it('registers and protects metric event storage', function (): void {
    expect(Schema::hasColumns('metric_events', [
        'occurred_at', 'owner_package', 'collector_key', 'metric_key', 'definition_hash',
        'scope_key', 'scope_type', 'site_id', 'site_uuid', 'language', 'timezone',
        'day_starts_at', 'value', 'weight',
    ]))->toBeTrue()
        ->and(CapellCore::getMigrations())->toContain('2026_07_22_000003_create_metric_events_table')
        ->and(CapellCore::getProtectedTables())->toContain('metric_events');
});

it('rolls up weighted events once and adds late arrivals without double counting', function (): void {
    CarbonImmutable::setTestNow('2026-07-22 10:00:00 UTC');
    $definition = metricEventPipelineDefinition();
    resolve(MetricEventRegistry::class)->register($definition);
    $store = resolve(StoreMetricEventAction::class);
    $store->execute($definition, 2, 5, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-21 00:00:00 UTC'));
    $store->execute($definition, 3, 2, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-21 23:59:59 UTC'));

    $action = resolve(RollupMetricEventsAction::class);

    expect($action->handle('2026-07-21'))->toBe(2)
        ->and($action->handle('2026-07-21'))->toBe(0)
        ->and(MetricDailyRollup::query()->sole()->value)->toBe('16');

    $store->execute($definition, 4, 1, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-21 12:00:00 UTC'));

    expect($action->handlePending())->toBe(1);

    $rollup = MetricDailyRollup::query()->sole();

    expect($rollup->value)->toBe('20')
        ->and($rollup->point_state)->toBe(MetricPointState::Present);
});

it('leaves events arriving after the day snapshot for the next idempotent run', function (): void {
    CarbonImmutable::setTestNow('2026-07-22 10:00:00 UTC');
    $definition = metricEventPipelineDefinition();
    resolve(MetricEventRegistry::class)->register($definition);
    $store = resolve(StoreMetricEventAction::class);
    $store->execute($definition, 2, 1, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-21 01:00:00 UTC'));

    $lateEventStored = false;

    DB::listen(function (QueryExecuted $query) use (&$lateEventStored, $definition, $store): void {
        if ($lateEventStored
            || ! str_contains(strtolower($query->sql), 'max(')
            || ! str_contains(strtolower($query->sql), 'metric_events')) {
            return;
        }

        $lateEventStored = true;
        $store->execute($definition, 3, 1, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-21 23:00:00 UTC'));
    });

    $action = resolve(RollupMetricEventsAction::class);

    expect($action->handle('2026-07-21'))->toBe(1)
        ->and(MetricDailyRollup::query()->sole()->value)->toBe('2')
        ->and(MetricEvent::query()->count())->toBe(1)
        ->and($action->handle('2026-07-21'))->toBe(1)
        ->and(MetricDailyRollup::query()->sole()->value)->toBe('5')
        ->and(MetricEvent::query()->count())->toBe(0);
});

it('uses UTC day boundaries even when the application timezone is not UTC', function (): void {
    config(['app.timezone' => 'Pacific/Auckland']);
    date_default_timezone_set('Pacific/Auckland');
    CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
    $definition = metricEventPipelineDefinition();
    resolve(MetricEventRegistry::class)->register($definition);
    $store = resolve(StoreMetricEventAction::class);
    $store->execute($definition, 1, 1, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-20 23:59:59 UTC'));
    $store->execute($definition, 2, 1, MetricScopeData::global('UTC'), CarbonImmutable::parse('2026-07-21 00:00:00 UTC'));

    expect(resolve(RollupMetricEventsAction::class)->handle('2026-07-21'))->toBe(1)
        ->and(MetricDailyRollup::query()->sole()->value)->toBe('2')
        ->and(MetricEvent::query()->count())->toBe(1);
});

it('rolls back every write when a stored definition hash has drifted', function (): void {
    $definition = metricEventPipelineDefinition();
    resolve(MetricEventRegistry::class)->register($definition);
    resolve(StoreMetricEventAction::class)->execute(
        $definition,
        1,
        1,
        MetricScopeData::global('UTC'),
        CarbonImmutable::parse('2026-07-21 12:00:00 UTC'),
    );
    MetricEvent::query()->update(['definition_hash' => str_repeat('f', 64)]);

    expect(fn (): int => resolve(RollupMetricEventsAction::class)->handle('2026-07-21'))
        ->toThrow(RuntimeException::class)
        ->and(MetricEvent::query()->count())->toBe(1)
        ->and(MetricDailyRollup::query()->count())->toBe(0);
});

function metricEventPipelineDefinition(
    string $owner = 'capell-app/core',
    MetricSemantic $semantic = MetricSemantic::Event,
): MetricDefinitionData {
    return new MetricDefinitionData(
        identity: new MetricIdentityData($owner, 'request_events', 'requests'),
        representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        scopeType: MetricScopeType::Global,
        semantics: new MetricSemanticsData($semantic, MetricAggregation::Sum, MetricGapPolicy::Missing, MetricBackfillPolicy::Unsupported),
        governance: new MetricGovernanceData(MetricSource::EventStream, 'request.completed', MetricSensitivity::Internal, MetricVisibility::SiteAdmin),
    );
}
