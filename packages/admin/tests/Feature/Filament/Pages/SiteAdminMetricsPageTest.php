<?php

declare(strict_types=1);

use Capell\Admin\Actions\Metrics\ReadSiteAdminMetricSeriesAction;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Filament\Pages\SiteAdminMetricsPage;
use Capell\Admin\Tests\Fixtures\Metrics\SiteAdminMetricsTestCollector;
use Capell\Core\Actions\Metrics\StoreMetricCollectionRunAction;
use Capell\Core\Actions\Metrics\StoreMetricDailyRollupAction;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('registers the host metrics page with stable translated labels', function (): void {
    expect(PageEnum::SiteAdminMetrics->value)->toBe(SiteAdminMetricsPage::class)
        ->and(SiteAdminMetricsPage::getNavigationLabel())->toBe(__('capell-admin::navigation.site_admin_metrics'))
        ->and(resolve(SiteAdminMetricsPage::class)->getTitle())->toBe(__('capell-admin::metrics.title'))
        ->and(resolve(SiteAdminMetricsPage::class)->getSubheading())->toBe(__('capell-admin::metrics.description'));
});

it('denies the metrics page without its admin permission', function (): void {
    test()->actingAsUser();

    get(SiteAdminMetricsPage::getUrl())->assertForbidden();
});

it('denies direct metric reads without the page permission', function (): void {
    test()->actingAsUser();

    ReadSiteAdminMetricSeriesAction::run(test()->authenticatedUser());
})->throws(AuthorizationException::class);

it('allows direct metric reads with the page permission', function (): void {
    Permission::create(['name' => ReadSiteAdminMetricSeriesAction::Permission, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(ReadSiteAdminMetricSeriesAction::Permission);

    expect(ReadSiteAdminMetricSeriesAction::run(test()->authenticatedUser()))->toBeArray();
});

it('denies global metric reads to a site-scoped actor with the page permission', function (): void {
    Permission::create(['name' => ReadSiteAdminMetricSeriesAction::Permission, 'guard_name' => 'web']);
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo(ReadSiteAdminMetricSeriesAction::Permission);

    ReadSiteAdminMetricSeriesAction::run(test()->authenticatedUser());
})->throws(AuthorizationException::class, 'Global metrics require a global administrator.');

it('reads and formats only active global site admin series', function (): void {
    CarbonImmutable::setTestNow('2026-07-24 12:00:00 UTC');
    Permission::create(['name' => ReadSiteAdminMetricSeriesAction::Permission, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(ReadSiteAdminMetricSeriesAction::Permission);

    $registry = resolve(MetricCollectorRegistry::class);
    $registry->register(SiteAdminMetricsTestCollector::class);

    $definition = $registry->definitions()
        ->firstOrFail(fn (MetricDefinitionData $candidate): bool => $candidate->identity->metricKey === 'visible-count');

    $run = resolve(StoreMetricCollectionRunAction::class)->execute(
        day: '2026-07-23',
        ownerPackage: $definition->identity->ownerPackage,
        collectorKey: $definition->identity->collectorKey,
        definitionHash: $definition->semanticHash(),
        status: MetricCollectionRunStatus::Started,
        startedAt: CarbonImmutable::parse('2026-07-24 00:05:00', 'UTC'),
    );
    resolve(StoreMetricDailyRollupAction::class)->execute(
        run: $run,
        definition: $definition,
        day: '2026-07-23',
        scope: MetricScopeData::global('UTC'),
        state: MetricPointState::Present,
        value: MetricValueData::integer(12),
    );
    $previousRun = resolve(StoreMetricCollectionRunAction::class)->execute(
        day: '2026-07-22',
        ownerPackage: $definition->identity->ownerPackage,
        collectorKey: $definition->identity->collectorKey,
        definitionHash: $definition->semanticHash(),
        status: MetricCollectionRunStatus::Started,
        startedAt: CarbonImmutable::parse('2026-07-23 00:04:00', 'UTC'),
    );
    resolve(StoreMetricDailyRollupAction::class)->execute(
        run: $previousRun,
        definition: $definition,
        day: '2026-07-22',
        scope: MetricScopeData::global('UTC'),
        state: MetricPointState::Present,
        value: MetricValueData::integer(10),
    );
    $percentageDefinition = $registry->definitions()
        ->firstOrFail(fn (MetricDefinitionData $candidate): bool => $candidate->identity->metricKey === 'visible-percentage');

    $percentageRun = resolve(StoreMetricCollectionRunAction::class)->execute(
        day: '2026-07-23',
        ownerPackage: $percentageDefinition->identity->ownerPackage,
        collectorKey: $percentageDefinition->identity->collectorKey,
        definitionHash: $percentageDefinition->semanticHash(),
        status: MetricCollectionRunStatus::Started,
        startedAt: CarbonImmutable::parse('2026-07-24 00:06:00', 'UTC'),
    );
    resolve(StoreMetricDailyRollupAction::class)->execute(
        run: $percentageRun,
        definition: $percentageDefinition,
        day: '2026-07-23',
        scope: MetricScopeData::global('UTC'),
        state: MetricPointState::Present,
        value: MetricValueData::decimal('12.5', 1),
    );

    $series = ReadSiteAdminMetricSeriesAction::run(test()->authenticatedUser());

    expect($series)->toHaveCount(4)
        ->and(collect($series)->pluck('label')->all())->toBe(['Visible count', 'Visible empty', 'Visible percentage', 'Visible trend'])
        ->and($series[0]->latestValue)->toBe('12')
        ->and($series[0]->points)->toHaveCount(2)
        ->and($series[0]->trend)->toBe([10.0, 12.0])
        ->and($series[1]->latestValue)->toBe(__('capell-admin::metrics.no_value'))
        ->and($series[2]->latestValue)->toBe('12.5%')
        ->and(collect($series)->pluck('label')->all())
        ->not->toContain('Operations only', 'Site scoped', 'Inactive');
});

it('renders a stable metrics evidence wrapper for an authorized admin', function (): void {
    Permission::create(['name' => 'View:SiteAdminMetricsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SiteAdminMetricsPage');

    resolve(MetricCollectorRegistry::class)->register(SiteAdminMetricsTestCollector::class);

    Livewire::test(SiteAdminMetricsPage::class)
        ->assertSuccessful()
        ->assertSeeHtml('data-testid="capell-site-admin-metrics"')
        ->assertSee('Visible count')
        ->assertSee('Visible empty')
        ->assertSee('Visible percentage')
        ->assertSee('Visible trend');
});
