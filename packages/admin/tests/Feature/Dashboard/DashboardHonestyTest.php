<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDefaultSiteStatsAction;
use Capell\Admin\Actions\Dashboard\ResolveDashboardPeriodWindowsAction;
use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Contracts\Dashboard\SiteStatsDataProvider;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Enums\DashboardAnalyticsAvailabilityEnum;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\SiteStatsOverviewFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Admin\Support\Dashboard\NullDashboardAnalyticsDataProvider;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('dashboard');

beforeEach(function (): void {
    test()->originalTimezone = date_default_timezone_get();
    Gate::before(fn (): bool => true);
    test()->actingAsAdmin();
    config()->set('app.timezone', 'Europe/London');
    date_default_timezone_set('Europe/London');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    date_default_timezone_set(test()->originalTimezone);
});

function resetDashboardRequestCache(): void
{
    app()->forgetInstance(AdminDashboardDataRequestCache::class);
}

it('states the rolling window each period covers for analytics and published pages', function (string $period, string $rolling, string $published): void {
    $windows = ResolveDashboardPeriodWindowsAction::make();

    expect($windows->describeRolling($period))->toBe($rolling)
        ->and($windows->describePublishedRolling($period))->toBe($published);
})->with([
    'today' => ['today', 'Figures cover the last 24 hours up to now (UTC).', 'Published pages: the last 24 hours up to now (UTC).'],
    'this week' => ['this_week', 'Figures cover the last 7 days up to now (UTC).', 'Published pages: the last 7 days up to now (UTC).'],
    'this month' => ['this_month', 'Figures cover the last 30 days up to now (UTC).', 'Published pages: the last 30 days up to now (UTC).'],
    'last 30 days' => ['last_30_days', 'Figures cover the last 30 days up to now (UTC).', 'Published pages: the last 30 days up to now (UTC).'],
    'this year' => ['this_year', 'Figures cover the last 365 days up to now (UTC).', 'Published pages: the last 365 days up to now (UTC).'],
]);

it('counts published pages over the same rolling window analytics reads', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'));

    $site = Site::factory()->createOne();
    Page::factory()->recycle($site)->createOne(['visible_from' => '2026-10-22 10:30:00']);
    Page::factory()->recycle($site)->createOne(['visible_from' => '2026-10-21 09:30:00']);

    expect(BuildDefaultSiteStatsAction::run('this_week')->publishedCount)->toBe(1)
        ->and(BuildDefaultSiteStatsAction::run('this_month')->publishedCount)->toBe(2);
});

it('restricts every site statistic to the site selected on the dashboard', function (): void {
    $first = Site::factory()->createOne();
    $second = Site::factory()->createOne();
    Page::factory()->recycle($first)->count(2)->create(['visible_from' => now()->subDay()]);
    Page::factory()->recycle($second)->count(3)->create(['visible_from' => now()->subDay()]);

    $firstPages = Page::query()->where('site_id', $first->getKey())->count();
    $secondPages = Page::query()->where('site_id', $second->getKey())->count();

    expect($firstPages)->not->toBe($secondPages)
        ->and(BuildDefaultSiteStatsAction::run('this_week', null, scoped: true)->totalPagesCount)->toBe($firstPages + $secondPages)
        ->and(BuildDefaultSiteStatsAction::run('this_week', $first->getKey(), scoped: true)->totalPagesCount)->toBe($firstPages)
        ->and(BuildDefaultSiteStatsAction::run('this_week', $first->getKey(), scoped: true)->publishedCount)->toBe(2)
        ->and(BuildDefaultSiteStatsAction::run('this_week', $second->getKey(), scoped: true)->totalPagesCount)->toBe($secondPages);
});

it('returns no counts for a site id the person cannot access instead of widening to every site', function (): void {
    Page::factory()->recycle(Site::factory()->createOne())->createOne();

    $data = BuildDefaultSiteStatsAction::run('this_week', 999999, scoped: true);

    expect($data->totalPagesCount)->toBe(0)
        ->and($data->publishedCount)->toBe(0)
        ->and($data->scoped)->toBeTrue();
});

it('passes the dashboard site to a scoped provider and states the counts follow it', function (): void {
    $first = Site::factory()->createOne(['name' => 'First Site']);
    Site::factory()->createOne(['name' => 'Second Site']);

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week', 'dashboardSiteId' => $first->getKey()])
        ->assertSee(__('capell-admin::dashboard.scope_applied_site', ['site' => 'First Site']))
        ->assertSee('Published pages: the last 7 days up to now (UTC).')
        ->assertDontSee(__('capell-admin::dashboard.scope_other_site', ['site' => 'First Site']));

    resetDashboardRequestCache();

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week'])
        ->assertSee(__('capell-admin::dashboard.scope_applied_all_sites'));
});

it('says the selected site is unavailable when the person cannot access it', function (): void {
    Site::factory()->createOne();

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week', 'dashboardSiteId' => 999999])
        ->assertSee(__('capell-admin::dashboard.scope_site_unavailable'));
});

it('keeps a provider that only implements the original contract working and discloses that the site filter does not apply', function (): void {
    $first = Site::factory()->createOne(['name' => 'First Site']);
    /** @var ArrayObject<int, string> $calls */
    $calls = new ArrayObject;

    app()->bind(SiteStatsDataProvider::class, fn (): SiteStatsDataProvider => new readonly class($calls) implements SiteStatsDataProvider
    {
        /** @param ArrayObject<int, string> $calls */
        public function __construct(private ArrayObject $calls) {}

        public function build(string $period): SiteStatsData
        {
            $this->calls->append($period);

            return new SiteStatsData(workQueueCount: 0, publishedCount: 7, sparklinePublished: []);
        }
    });
    resetDashboardRequestCache();

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week', 'dashboardSiteId' => $first->getKey()])
        ->assertSee('7')
        ->assertSee(__('capell-admin::dashboard.scope_other_site', ['site' => 'First Site']));

    expect($calls->getArrayCopy())->toContain('this_week');
});

it('states the rolling window on the analytics overview', function (): void {
    Livewire::test(AnalyticsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week'])
        ->assertSee('Figures cover the last 7 days up to now (UTC).');
});

it('shows successful zero activity as zero rather than as unavailable', function (): void {
    resetDashboardRequestCache();

    Livewire::test(AnalyticsOverviewFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_observed_description'))
        ->assertDontSee(__('capell-admin::dashboard.analytics_unavailable_description'));

    Livewire::test(AnalyticsInsightsFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_no_insights'))
        ->assertDontSee(__('capell-admin::dashboard.analytics_unavailable_description'));
});

it('shows a provider failure as unavailable instead of zero activity in the overview and insights', function (): void {
    resetDashboardRequestCache();
    Schema::drop('activity_buckets');

    Livewire::test(AnalyticsOverviewFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_unavailable_description'))
        ->assertDontSee(__('capell-admin::dashboard.analytics_observed_description'));

    resetDashboardRequestCache();

    Livewire::test(AnalyticsInsightsFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_unavailable_description'))
        ->assertDontSee(__('capell-admin::dashboard.analytics_no_insights'));
});

it('does not leak failure details into the unavailable analytics state', function (): void {
    resetDashboardRequestCache();
    Schema::drop('activity_buckets');

    Livewire::test(AnalyticsOverviewFilamentWidget::class)
        ->assertDontSee('activity_buckets')
        ->assertDontSee('SQLSTATE');
});

it('shows an intentionally null provider as not configured, distinct from a failure', function (): void {
    app()->singleton(DashboardAnalyticsDataProvider::class, NullDashboardAnalyticsDataProvider::class);
    resetDashboardRequestCache();

    Livewire::test(AnalyticsOverviewFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_not_configured_description'))
        ->assertDontSee(__('capell-admin::dashboard.analytics_unavailable_description'));

    resetDashboardRequestCache();

    Livewire::test(AnalyticsInsightsFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_not_configured_description'));
});

it('keeps earlier observations visible and says collection is off when collection is disabled', function (): void {
    resetDashboardRequestCache();
    $settings = AdminSettings::instance();
    $settings->analytics_collection_enabled = false;
    $settings->save();

    Livewire::test(AnalyticsOverviewFilamentWidget::class)
        ->assertSee(__('capell-admin::dashboard.analytics_collection_off_description'))
        ->assertDontSee(__('capell-admin::dashboard.analytics_unavailable_description'));
});

it('marks provider failure, null provider and normal snapshots with distinct availability', function (): void {
    $actor = test()->authenticatedUser();
    $null = (new NullDashboardAnalyticsDataProvider)->build($actor, 'this_week');

    expect($null->availability)->toBe(DashboardAnalyticsAvailabilityEnum::NotConfigured)
        ->and($null->isAvailable())->toBeFalse();

    Schema::drop('activity_buckets');
    $failed = resolve(DashboardAnalyticsDataProvider::class)->build($actor, 'this_week');

    expect($failed->availability)->toBe(DashboardAnalyticsAvailabilityEnum::Unavailable)
        ->and($failed->isAvailable())->toBeFalse();
});
