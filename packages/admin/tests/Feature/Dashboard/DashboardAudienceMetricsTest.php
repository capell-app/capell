<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDashboardAnalyticsSnapshotAction;
use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Actions\Metrics\StoreMetricCollectionRunAction;
use Capell\Core\Actions\Metrics\StoreMetricDailyRollupAction;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Carbon\CarbonImmutable;

function recordVisitorDay(Site $site, string $hash, CarbonImmutable $at, string $language = 'en'): void
{
    ActivityVisitor::query()->create([
        'site_id' => $site->getKey(),
        'language' => $language,
        'day' => $at->toDateString(),
        'visitor_hash' => $hash,
        'first_seen_at' => $at,
    ]);
}

function recordDashboardPageViewRollup(Site $site, string $day, int $views): void
{
    $definition = (new ActivityBucketsDailyMetricsCollector)->definitions()[0];
    $run = resolve(StoreMetricCollectionRunAction::class)->execute(
        day: $day,
        ownerPackage: $definition->identity->ownerPackage,
        collectorKey: $definition->identity->collectorKey,
        definitionHash: $definition->semanticHash(),
        status: MetricCollectionRunStatus::Started,
        startedAt: CarbonImmutable::parse($day . ' 23:59:59', 'UTC'),
    );

    resolve(StoreMetricDailyRollupAction::class)->execute(
        run: $run,
        definition: $definition,
        day: $day,
        scope: MetricScopeData::siteLanguage($site->uuid, 'en', 'UTC'),
        state: MetricPointState::Present,
        value: MetricValueData::integer($views),
        siteId: (int) $site->getKey(),
    );
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('counts each visitor once per day and never twice within one day', function (): void {
    $site = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC');

    // The salt rotates daily, so one person browsing on three days is three
    // unlinkable hashes. Within a day the hash is stable, so several sessions
    // on the same day collapse to the single stored row.
    foreach ([1, 2, 3] as $daysAgo) {
        recordVisitorDay($site, str_repeat((string) $daysAgo, 32), $now->subDays($daysAgo));
    }

    recordVisitorDay($site, str_repeat('b', 32), $now->subDay());

    $actor = test()->createUserWithRole('super_admin');
    $snapshot = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect(ActivityVisitor::query()->count())->toBe(4)
        ->and($snapshot->visitors)->toBe(4);
});

it('scopes visitors to the sites an actor is assigned', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC')->subDay();

    recordVisitorDay($assignedSite, str_repeat('a', 32), $now);
    recordVisitorDay($otherSite, str_repeat('b', 32), $now);

    $actor = ScopedAdminUser::make(collect([$assignedSite->getKey()]));
    $action = resolve(BuildDashboardAnalyticsSnapshotAction::class);

    expect($action->handle($actor, 'this_week')->visitors)->toBe(1)
        ->and($action->handle($actor, 'this_week', $otherSite->getKey())->visitors)->toBe(0);
});

it('suppresses the comparison until a previous period exists', function (): void {
    $site = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC');

    recordVisitorDay($site, str_repeat('a', 32), $now->subDay());

    $actor = test()->createUserWithRole('super_admin');
    $fresh = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect($fresh->hasPreviousAudiencePeriod)->toBeFalse()
        ->and($fresh->visitorsChangePercent())->toBeNull();

    recordVisitorDay($site, str_repeat('b', 32), $now->subDays(9));
    recordVisitorDay($site, str_repeat('c', 32), $now->subDays(10));

    $compared = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect($compared->hasPreviousAudiencePeriod)->toBeTrue()
        ->and($compared->previousVisitors)->toBe(2)
        ->and($compared->visitorsChangePercent())->toBe(-50.0);
});

it('derives views per visitor and stays null without visitors', function (): void {
    $site = Site::factory()->createOne();
    $now = CarbonImmutable::now('UTC')->subDay();

    ActivityBucket::query()->create([
        'site_id' => $site->getKey(),
        'language' => 'en',
        'subject_type' => ActivityBucketSubjectEnum::PageView,
        'subject_key' => '1',
        'bucket_started_at' => $now,
        'count' => 5,
    ]);

    $actor = test()->createUserWithRole('super_admin');
    $action = resolve(BuildDashboardAnalyticsSnapshotAction::class);

    expect($action->handle($actor, 'this_week')->viewsPerVisitor())->toBeNull();

    recordVisitorDay($site, str_repeat('a', 32), $now);
    recordVisitorDay($site, str_repeat('b', 32), $now);

    expect($action->handle($actor, 'this_week')->viewsPerVisitor())->toBe(2.5);
});

it('compares equal-length complete UTC day windows for views and visitors', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 12:00:00', 'UTC'));
    $site = Site::factory()->createOne();

    recordDashboardPageViewRollup($site, '2026-08-11', 5);
    recordDashboardPageViewRollup($site, '2026-08-17', 5);
    recordDashboardPageViewRollup($site, '2026-08-04', 8);
    recordDashboardPageViewRollup($site, '2026-08-10', 12);
    ActivityBucket::query()->create([
        'site_id' => $site->getKey(),
        'language' => 'en',
        'subject_type' => ActivityBucketSubjectEnum::PageView,
        'subject_key' => '1',
        'bucket_started_at' => CarbonImmutable::parse('2026-08-18 09:00:00', 'UTC'),
        'count' => 100,
    ]);

    recordVisitorDay($site, str_repeat('a', 32), CarbonImmutable::parse('2026-08-11 00:05:00', 'UTC'));
    recordVisitorDay($site, str_repeat('b', 32), CarbonImmutable::parse('2026-08-17 23:59:00', 'UTC'));
    recordVisitorDay($site, str_repeat('c', 32), CarbonImmutable::parse('2026-08-04 00:05:00', 'UTC'));
    recordVisitorDay($site, str_repeat('d', 32), CarbonImmutable::parse('2026-08-10 23:59:00', 'UTC'));
    recordVisitorDay($site, str_repeat('e', 32), CarbonImmutable::parse('2026-08-18 09:00:00', 'UTC'));

    $snapshot = resolve(BuildDashboardAnalyticsSnapshotAction::class)
        ->handle(test()->createUserWithRole('super_admin'), 'this_week', $site->getKey());

    expect($snapshot->totalViews)->toBe(110)
        ->and($snapshot->recentViews)->toBe(100)
        ->and($snapshot->visitors)->toBe(2)
        ->and($snapshot->audienceViews)->toBe(10)
        ->and($snapshot->viewsPerVisitor())->toBe(5.0)
        ->and($snapshot->previousAudienceViews)->toBe(20)
        ->and($snapshot->previousVisitors)->toBe(2)
        ->and($snapshot->audienceViewsChangePercent())->toBe(-50.0)
        ->and($snapshot->visitorsChangePercent())->toBe(0.0)
        ->and($snapshot->hasPreviousAudiencePeriod)->toBeTrue();
});

it('renders audience stats as no data before activity collection produces data', function (): void {
    test()->actingAs(test()->createUserWithRole('super_admin'));

    $stats = collect(CapellAdmin::getOverviewStats())->keyBy('key');
    $visitors = $stats->get('capell_overview.visitors');
    $viewsPerVisitor = $stats->get('capell_overview.views_per_visitor');

    expect($visitors)->toBeInstanceOf(CapellOverviewStatData::class)
        ->and($viewsPerVisitor)->toBeInstanceOf(CapellOverviewStatData::class);
    assert($visitors instanceof CapellOverviewStatData);
    assert($viewsPerVisitor instanceof CapellOverviewStatData);

    expect($visitors->value)->toBe(__('capell-admin::dashboard.overview_stat_no_data'))
        ->and($visitors->description)->toBe(__('capell-admin::dashboard.overview_stat_no_data'))
        ->and($viewsPerVisitor->value)->toBe(__('capell-admin::dashboard.overview_stat_no_data'))
        ->and($viewsPerVisitor->description)->toBe(__('capell-admin::dashboard.overview_stat_no_data'));
});

it('renders audience stats as no data when page-view rollups exist without visitor data', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 12:00:00', 'UTC'));
    $site = Site::factory()->createOne();
    $actor = test()->createUserWithRole('super_admin');

    recordDashboardPageViewRollup($site, '2026-08-11', 12);

    $snapshot = resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, 'this_week');

    expect($snapshot->totalViews)->toBe(12)
        ->and($snapshot->visitors)->toBe(0)
        ->and($snapshot->previousVisitors)->toBe(0)
        ->and($snapshot->hasVisitorSeries())->toBeFalse();

    test()->actingAs($actor);

    $stats = collect(CapellAdmin::getOverviewStats())->keyBy('key');
    $visitors = $stats->get('capell_overview.visitors');
    $viewsPerVisitor = $stats->get('capell_overview.views_per_visitor');

    expect($visitors)->toBeInstanceOf(CapellOverviewStatData::class)
        ->and($viewsPerVisitor)->toBeInstanceOf(CapellOverviewStatData::class);
    assert($visitors instanceof CapellOverviewStatData);
    assert($viewsPerVisitor instanceof CapellOverviewStatData);

    expect($visitors->value)->toBe(__('capell-admin::dashboard.overview_stat_no_data'))
        ->and($viewsPerVisitor->value)->toBe(__('capell-admin::dashboard.overview_stat_no_data'));
});

it('shows the audience stats by default and hides the inventory counts', function (): void {
    $keys = collect(CapellAdmin::getOverviewStats())->pluck('key');
    $allKeys = collect(CapellAdmin::getOverviewStats(false))->pluck('key');

    expect($keys)->toContain('capell_overview.visitors')
        ->and($keys)->toContain('capell_overview.views_per_visitor')
        ->and($keys)->not->toContain('capell_overview.pages')
        ->and($keys)->not->toContain('capell_overview.sites')
        // Demoted, never removed: the registry keys still exist for extensions
        // that assert on them, and an operator can switch them back on.
        ->and($allKeys)->toContain('capell_overview.pages')
        ->and($allKeys)->toContain('capell_overview.sites')
        ->and($allKeys)->toContain('capell_overview.languages')
        ->and($allKeys)->toContain('capell_overview.page_types');
});
