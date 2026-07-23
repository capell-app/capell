<?php

declare(strict_types=1);

use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricReadContextData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricReaderType;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Carbon\CarbonImmutable;

function metricIdentity(string $key = 'traffic.page_views'): MetricIdentityData
{
    return new MetricIdentityData('capell-app/insights', 'daily-traffic', $key);
}

function metricDefinition(array $overrides = []): MetricDefinitionData
{
    return new MetricDefinitionData(...array_merge([
        'identity' => metricIdentity(),
        'representation' => new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        'scopeType' => MetricScopeType::SiteLanguage,
        'semantics' => new MetricSemanticsData(
            MetricSemantic::Counter,
            MetricAggregation::Sum,
            MetricGapPolicy::Missing,
            MetricBackfillPolicy::Supported,
        ),
        'governance' => new MetricGovernanceData(
            MetricSource::Database,
            'analytics.page-views',
            MetricSensitivity::Internal,
            MetricVisibility::SiteAdmin,
        ),
        'labels' => ['en-GB' => 'Page views'],
        'descriptions' => ['en-GB' => 'Daily page views.'],
    ], $overrides));
}

it('serialises cohesive definition semantics with stable source ownership', function (): void {
    $definition = metricDefinition();

    expect($definition->toArray())->toMatchArray([
        'schema_version' => 1,
        'identity' => [
            'owner_package' => 'capell-app/insights',
            'collector_key' => 'daily-traffic',
            'metric_key' => 'traffic.page_views',
        ],
        'representation' => [
            'unit' => 'count',
            'value_type' => 'integer',
            'scale' => null,
            'currency' => null,
        ],
        'scope_type' => 'site_language',
        'semantics' => [
            'semantic' => 'counter',
            'aggregation' => 'sum',
            'gap_policy' => 'missing',
            'backfill_policy' => 'supported',
        ],
        'governance' => [
            'source' => 'database',
            'authoritative_source_key' => 'analytics.page-views',
            'sensitivity' => 'internal',
            'visibility' => 'site_admin',
        ],
        'retention_days' => null,
        'semantic_hash' => $definition->semanticHash(),
    ])->and($definition->retentionDays())->toBeNull();
});

it('excludes translations from the semantic hash but includes fixed financial representation', function (): void {
    $english = metricDefinition();
    $translated = metricDefinition(['labels' => ['cy' => 'Golygfeydd'], 'descriptions' => []]);
    $gbp = metricDefinition([
        'representation' => new MetricRepresentationData(
            MetricUnitEnum::MinorCurrencyUnit,
            MetricValueType::MinorCurrencyUnit,
            2,
            'GBP',
        ),
    ]);

    expect($english->semanticHash())->toBe($translated->semanticHash())
        ->not->toBe($gbp->semanticHash());
});

it('has a golden canonical semantic JSON and SHA-256 vector', function (): void {
    $definition = metricDefinition();
    $golden = '{"schema_version":1,"identity":{"owner_package":"capell-app/insights","collector_key":"daily-traffic","metric_key":"traffic.page_views"},"representation":{"unit":"count","value_type":"integer","scale":null,"currency":null},"scope_type":"site_language","semantics":{"semantic":"counter","aggregation":"sum","gap_policy":"missing","backfill_policy":"supported"},"governance":{"source":"database","authoritative_source_key":"analytics.page-views","sensitivity":"internal","visibility":"site_admin"},"status":"active","replaces":null,"replaced_by":null,"retention_days":null}';

    expect($definition->semanticJson())->toBe($golden)
        ->and($definition->semanticHash())->toBe('d92e0a4d07028a28ec30ea1cc6cf20ff2c22969451a518da1cc28af4d1995954');
});

it('hashes every semantic definition field and excludes every translation field', function (): void {
    $baseline = metricDefinition();
    $mutations = [
        'owner package' => metricDefinition(['identity' => new MetricIdentityData('vendor/insights', 'daily-traffic', 'traffic.page_views')]),
        'collector key' => metricDefinition(['identity' => new MetricIdentityData('capell-app/insights', 'weekly-traffic', 'traffic.page_views')]),
        'metric key' => metricDefinition(['identity' => metricIdentity('traffic.unique_views')]),
        'unit and value type' => metricDefinition(['representation' => new MetricRepresentationData(MetricUnitEnum::Bytes, MetricValueType::Integer)]),
        'scale and currency' => metricDefinition(['representation' => new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'GBP')]),
        'semantic and aggregation' => metricDefinition([
            'representation' => new MetricRepresentationData(MetricUnitEnum::Percentage, MetricValueType::Decimal, 4),
            'semantics' => new MetricSemanticsData(MetricSemantic::Ratio, MetricAggregation::Average, MetricGapPolicy::Missing, MetricBackfillPolicy::Supported),
        ]),
        'scope type' => metricDefinition(['scopeType' => MetricScopeType::Site]),
        'semantic' => metricDefinition(['semantics' => new MetricSemanticsData(MetricSemantic::Event, MetricAggregation::Sum, MetricGapPolicy::Missing, MetricBackfillPolicy::Supported)]),
        'gap policy' => metricDefinition(['semantics' => new MetricSemanticsData(MetricSemantic::Counter, MetricAggregation::Sum, MetricGapPolicy::CarryForward, MetricBackfillPolicy::Supported)]),
        'backfill policy' => metricDefinition(['semantics' => new MetricSemanticsData(MetricSemantic::Counter, MetricAggregation::Sum, MetricGapPolicy::Missing, MetricBackfillPolicy::CurrentDayOnly)]),
        'source' => metricDefinition(['governance' => new MetricGovernanceData(MetricSource::EventStream, 'analytics.page-views', MetricSensitivity::Internal, MetricVisibility::SiteAdmin)]),
        'authoritative source key' => metricDefinition(['governance' => new MetricGovernanceData(MetricSource::Database, 'analytics.unique-views', MetricSensitivity::Internal, MetricVisibility::SiteAdmin)]),
        'sensitivity' => metricDefinition(['governance' => new MetricGovernanceData(MetricSource::Database, 'analytics.page-views', MetricSensitivity::Confidential, MetricVisibility::SiteAdmin)]),
        'visibility' => metricDefinition(['governance' => new MetricGovernanceData(MetricSource::Database, 'analytics.page-views', MetricSensitivity::Internal, MetricVisibility::PlatformOps)]),
        'status' => metricDefinition(['status' => MetricDefinitionStatus::Tombstoned]),
        'replaces' => metricDefinition(['replaces' => metricIdentity('traffic.views')]),
        'replaced by' => metricDefinition(['status' => MetricDefinitionStatus::Tombstoned, 'replacedBy' => metricIdentity('traffic.views_v2')]),
    ];

    foreach ($mutations as $mutation) {
        expect($mutation->semanticHash())->not->toBe($baseline->semanticHash());
    }

    expect(metricDefinition(['labels' => ['cy' => 'Golygfeydd']])->semanticHash())->toBe($baseline->semanticHash())
        ->and(metricDefinition(['descriptions' => ['cy' => 'Dyddiol']])->semanticHash())->toBe($baseline->semanticHash());

    $decimalScaleThree = metricDefinition(['representation' => new MetricRepresentationData(MetricUnitEnum::Decimal, MetricValueType::Decimal, 3)]);
    $decimalScaleFour = metricDefinition(['representation' => new MetricRepresentationData(MetricUnitEnum::Decimal, MetricValueType::Decimal, 4)]);
    $gbp = metricDefinition(['representation' => new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'GBP')]);
    $usd = metricDefinition(['representation' => new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'USD')]);
    $gaugeLast = metricDefinition(['semantics' => new MetricSemanticsData(MetricSemantic::Gauge, MetricAggregation::Last, MetricGapPolicy::Missing, MetricBackfillPolicy::Supported)]);
    $gaugeMaximum = metricDefinition(['semantics' => new MetricSemanticsData(MetricSemantic::Gauge, MetricAggregation::Maximum, MetricGapPolicy::Missing, MetricBackfillPolicy::Supported)]);

    expect($decimalScaleThree->semanticHash())->not->toBe($decimalScaleFour->semanticHash())
        ->and($gbp->semanticHash())->not->toBe($usd->semanticHash())
        ->and($gaugeLast->semanticHash())->not->toBe($gaugeMaximum->semanticHash());
});

it('requires explicit portable scope identity and day boundaries', function (): void {
    $scope = MetricScopeData::siteLanguage(
        '019f8a4a-1ac5-7c00-823b-47ff4d079034',
        'en-GB',
        'Europe/London',
        '04:00:00',
    );

    expect($scope->toArray())->toMatchArray([
        'type' => 'site_language',
        'timezone' => 'Europe/London',
        'day_starts_at' => '04:00:00',
        'language' => 'en-GB',
    ])->and(fn (): MetricScopeData => MetricScopeData::siteLanguage(
        '019f8a4a-1ac5-7c00-823b-47ff4d079034',
        'en-gb',
        'Europe/London',
    ))->toThrow(InvalidArgumentException::class);
});

it('round trips every nested DTO through snake-case wire data', function (): void {
    $definition = metricDefinition();
    $scope = MetricScopeData::global('UTC');
    $value = MetricValueData::integer(0);
    $sample = new MetricSampleData($definition->identity, $definition->semanticHash(), '2026-07-21', $scope, $definition->representation, $value);
    $result = new MetricCollectionResultData(MetricCollectionStatus::Complete, '2026-07-21', [$scope], [$sample], 'events:1', str_repeat('a', 64), null);
    $readContext = new MetricReadContextData(MetricReaderType::System, $scope, CarbonImmutable::parse('2026-07-21T12:00:00Z'), 'daily-report', 'scheduler');
    $dataObjects = [
        $definition->identity,
        $definition->representation,
        $definition->semantics,
        $definition->governance,
        $definition,
        $scope,
        $value,
        $sample,
        $result,
        $readContext,
    ];

    foreach ($dataObjects as $dataObject) {
        $class = $dataObject::class;

        expect($class::from($dataObject->toArray())->toArray())->toBe($dataObject->toArray());
    }
});

it('accepts only canonical fixed-scale decimal values', function (string $value, int $scale, bool $valid): void {
    $create = fn (): MetricValueData => MetricValueData::decimal($value, $scale);

    if ($valid) {
        expect($create()->decimal)->toBe($value);

        return;
    }

    expect($create)->toThrow(InvalidArgumentException::class);
})->with([
    'zero scale zero' => ['0', 0, true],
    'fixed zero' => ['0.00', 2, true],
    'negative fixed value' => ['-12.30', 2, true],
    'maximum scale' => ['1.000000000000000000', 18, true],
    'leading zero' => ['01.00', 2, false],
    'multiple leading zeros' => ['00', 0, false],
    'negative zero' => ['-0', 0, false],
    'negative fixed zero' => ['-0.00', 2, false],
    'missing integer' => ['.50', 2, false],
    'explicit plus' => ['+1.00', 2, false],
    'missing fraction' => ['1', 2, false],
    'wrong scale' => ['1.0', 2, false],
    'excessive scale' => ['1.0000000000000000000', 19, false],
]);

it('preserves integer money boundaries and rejects invalid fixed currency metadata', function (): void {
    expect(MetricValueData::money(PHP_INT_MAX, 'GBP', 18)->minorUnits)->toBe(PHP_INT_MAX)
        ->and(MetricValueData::money(PHP_INT_MIN, 'JPY', 0)->minorUnits)->toBe(PHP_INT_MIN)
        ->and(fn (): MetricValueData => MetricValueData::money(1, 'gbp', 2))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricValueData => MetricValueData::money(1, 'GBP', 19))->toThrow(InvalidArgumentException::class);
});

it('enforces value representation and preserves explicit zero versus missing', function (): void {
    $definition = metricDefinition();
    $scope = MetricScopeData::global('UTC');
    $zero = new MetricSampleData(
        $definition->identity,
        $definition->semanticHash(),
        '2026-07-21',
        $scope,
        $definition->representation,
        MetricValueData::integer(0),
    );

    $withZero = new MetricCollectionResultData(
        MetricCollectionStatus::Complete,
        '2026-07-21',
        [$scope],
        [$zero],
        'events:1042',
        str_repeat('a', 64),
        null,
    );
    $missing = new MetricCollectionResultData(
        MetricCollectionStatus::Complete,
        '2026-07-21',
        [$scope],
        [],
        'events:1042',
        str_repeat('a', 64),
        null,
    );

    expect($withZero->samples[0]->value->integer)->toBe(0)
        ->and($missing->samples)->toBe([])
        ->and(fn (): MetricSampleData => new MetricSampleData(
            $definition->identity,
            $definition->semanticHash(),
            '2026-07-21',
            $scope,
            new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'GBP'),
            MetricValueData::money(100, 'USD', 2),
        ))->toThrow(InvalidArgumentException::class);
});

it('uses fully qualified replacement identities and explicit unsupported results', function (): void {
    $oldIdentity = metricIdentity('traffic.views');
    $newIdentity = metricIdentity();
    $tombstone = metricDefinition([
        'identity' => $oldIdentity,
        'status' => MetricDefinitionStatus::Tombstoned,
        'replacedBy' => $newIdentity,
    ]);
    $replacement = metricDefinition(['replaces' => $oldIdentity]);
    $unsupported = new MetricCollectionResultData(
        MetricCollectionStatus::Unsupported,
        '2026-07-21',
        [],
        [],
        null,
        null,
        'Collector does not support historical days.',
    );

    expect($tombstone->replacedBy?->key())->toBe($newIdentity->key())
        ->and($replacement->replaces?->key())->toBe($oldIdentity->key())
        ->and($unsupported->status)->toBe(MetricCollectionStatus::Unsupported)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Unsupported,
            '2026-07-21',
            [],
            [],
            null,
            null,
            null,
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects every invalid scope and representation constructor branch', function (): void {
    $uuid = '019f8a4a-1ac5-7c00-823b-47ff4d079034';

    expect(fn (): MetricScopeData => new MetricScopeData(MetricScopeType::Global, 'UTC', '00:00:00', $uuid))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricScopeData => new MetricScopeData(MetricScopeType::Site, 'UTC', '00:00:00'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricScopeData => new MetricScopeData(MetricScopeType::SiteLanguage, 'UTC', '00:00:00', $uuid))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricScopeData => MetricScopeData::site('not-a-uuid', 'UTC'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricScopeData => MetricScopeData::global('Not/A_Zone'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricScopeData => MetricScopeData::global('UTC', '24:00:00'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricRepresentationData => new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Decimal, 2))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricRepresentationData => new MetricRepresentationData(MetricUnitEnum::Decimal, MetricValueType::Decimal))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricRepresentationData => new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'gbp'))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid identity governance value and translation branches', function (): void {
    expect(fn (): MetricIdentityData => new MetricIdentityData('missing-slash', 'daily-traffic', 'traffic.views'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricIdentityData => new MetricIdentityData('vendor/package', 'DailyTraffic', 'traffic.views'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricGovernanceData => new MetricGovernanceData(MetricSource::Database, 'Analytics.Views', MetricSensitivity::Internal, MetricVisibility::SiteAdmin))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricValueData => new MetricValueData(MetricValueType::Integer))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricValueData => new MetricValueData(MetricValueType::Integer, integer: 1, decimal: '1.00'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricValueData => new MetricValueData(MetricValueType::Integer, integer: 1, scale: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricDefinitionData => metricDefinition(['labels' => ['en-GB' => '']]))->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate coverage and samples outside collection membership', function (): void {
    $definition = metricDefinition();
    $global = MetricScopeData::global('UTC');
    $site = MetricScopeData::site('019f8a4a-1ac5-7c00-823b-47ff4d079034', 'UTC');
    $globalSample = new MetricSampleData($definition->identity, $definition->semanticHash(), '2026-07-21', $global, $definition->representation, MetricValueData::integer(1));
    $wrongDaySample = new MetricSampleData($definition->identity, $definition->semanticHash(), '2026-07-20', $site, $definition->representation, MetricValueData::integer(1));

    expect(fn (): MetricCollectionResultData => new MetricCollectionResultData(
        MetricCollectionStatus::Complete,
        '2026-07-21',
        [$global, $global],
        [],
        'events:1',
        str_repeat('a', 64),
        null,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Complete,
            '2026-07-21',
            [$global],
            [$globalSample, $globalSample],
            'events:1',
            str_repeat('a', 64),
            null,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Complete,
            '2026-07-21',
            [$site],
            [$globalSample],
            'events:1',
            str_repeat('a', 64),
            null,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Complete,
            '2026-07-21',
            [$site],
            [$wrongDaySample],
            'events:1',
            str_repeat('a', 64),
            null,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Complete,
            '2026-07-21',
            [$site],
            [],
            'events:1',
            'invalid',
            null,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Failed,
            '2026-07-21',
            [$global],
            [$globalSample],
            null,
            null,
            'failed',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricCollectionResultData => new MetricCollectionResultData(
            MetricCollectionStatus::Unsupported,
            '2026-07-21',
            [$global],
            [],
            null,
            null,
            'unsupported',
        ))->toThrow(InvalidArgumentException::class);
});

it('requires explicit valid reader identity and purpose branches', function (): void {
    $scope = MetricScopeData::global('UTC');
    $requestedAt = CarbonImmutable::parse('2026-07-21T12:00:00Z');

    expect(fn (): MetricReadContextData => new MetricReadContextData(MetricReaderType::Anonymous, $scope, $requestedAt, 'dashboard', 'unexpected'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricReadContextData => new MetricReadContextData(MetricReaderType::User, $scope, $requestedAt, 'dashboard'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricReadContextData => new MetricReadContextData(MetricReaderType::System, $scope, $requestedAt, 'dashboard', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricReadContextData => new MetricReadContextData(MetricReaderType::Anonymous, $scope, $requestedAt, ''))->toThrow(InvalidArgumentException::class);
});

it('rejects ambiguous or cross-owner replacement identities', function (): void {
    expect(fn (): MetricDefinitionData => metricDefinition(['replaces' => metricIdentity()]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricDefinitionData => metricDefinition([
            'replaces' => new MetricIdentityData('vendor/insights', 'daily-traffic', 'traffic.views'),
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricDefinitionData => metricDefinition([
            'replaces' => new MetricIdentityData('capell-app/insights', 'weekly-traffic', 'traffic.views'),
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricDefinitionData => metricDefinition([
            'status' => MetricDefinitionStatus::Active,
            'replacedBy' => metricIdentity('traffic.next'),
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): MetricDefinitionData => metricDefinition([
            'status' => MetricDefinitionStatus::Tombstoned,
            'replaces' => metricIdentity('traffic.previous'),
        ]))->toThrow(InvalidArgumentException::class);
});

dataset('metric compatibility matrix', function (): iterable {
    $representations = [
        'count' => new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        'decimal' => new MetricRepresentationData(MetricUnitEnum::Decimal, MetricValueType::Decimal, 3),
        'money' => new MetricRepresentationData(MetricUnitEnum::MinorCurrencyUnit, MetricValueType::MinorCurrencyUnit, 2, 'GBP'),
        'percentage' => new MetricRepresentationData(MetricUnitEnum::Percentage, MetricValueType::Decimal, 4),
        'milliseconds' => new MetricRepresentationData(MetricUnitEnum::Milliseconds, MetricValueType::Integer),
        'bytes' => new MetricRepresentationData(MetricUnitEnum::Bytes, MetricValueType::Integer),
    ];

    foreach ($representations as $representationName => $representation) {
        foreach (MetricSemantic::cases() as $semantic) {
            foreach (MetricAggregation::cases() as $aggregation) {
                $valid = match ($semantic) {
                    MetricSemantic::Event => $representation->unit === MetricUnitEnum::Count && $aggregation === MetricAggregation::Sum,
                    MetricSemantic::Ratio => $representation->unit === MetricUnitEnum::Percentage && $aggregation === MetricAggregation::Average,
                    MetricSemantic::Counter => $aggregation === MetricAggregation::Sum,
                    MetricSemantic::Gauge => $aggregation !== MetricAggregation::Sum
                        && ($aggregation !== MetricAggregation::Average || $representation->valueType === MetricValueType::Decimal),
                };

                yield $representationName . ':' . $semantic->value . ':' . $aggregation->value => [
                    $representation,
                    $semantic,
                    $aggregation,
                    $valid,
                ];
            }
        }
    }
});

it('enforces the full unit value semantic and aggregation compatibility matrix', function (
    MetricRepresentationData $representation,
    MetricSemantic $semantic,
    MetricAggregation $aggregation,
    bool $valid,
): void {
    $create = fn (): MetricDefinitionData => metricDefinition([
        'representation' => $representation,
        'semantics' => new MetricSemanticsData(
            $semantic,
            $aggregation,
            MetricGapPolicy::Missing,
            MetricBackfillPolicy::Supported,
        ),
    ]);

    if ($valid) {
        expect($create())->toBeInstanceOf(MetricDefinitionData::class);

        return;
    }

    expect($create)->toThrow(InvalidArgumentException::class);
})->with('metric compatibility matrix');
