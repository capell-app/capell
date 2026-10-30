<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDefaultSiteStatsAction;
use Capell\Admin\Actions\Dashboard\ResolveDashboardPeriodWindowsAction;
use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
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

it('states the rolling analytics window and the calendar window for the same period at midweek', function (string $period, string $rolling, string $calendar): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 10:00:00', 'Europe/London'));

    $windows = ResolveDashboardPeriodWindowsAction::make();

    expect($windows->describeRolling($period))->toBe($rolling)
        ->and($windows->describeCalendar($period))->toBe($calendar);
})->with([
    'today' => ['today', 'Figures cover the last 24 hours up to now (UTC).', '28 Oct 2026 to 28 Oct 2026 (Europe/London)'],
    'this week' => ['this_week', 'Figures cover the last 7 days up to now (UTC).', '26 Oct 2026 to 1 Nov 2026 (Europe/London)'],
    'this month' => ['this_month', 'Figures cover the last 30 days up to now (UTC).', '1 Oct 2026 to 31 Oct 2026 (Europe/London)'],
    'last 30 days' => ['last_30_days', 'Figures cover the last 30 days up to now (UTC).', '28 Sep 2026 to 28 Oct 2026 (Europe/London)'],
    'this year' => ['this_year', 'Figures cover the last 365 days up to now (UTC).', '1 Jan 2026 to 31 Dec 2026 (Europe/London)'],
]);

it('counts published pages over exactly the calendar window the dashboard describes', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 10:00:00', 'Europe/London'));

    $site = Site::factory()->createOne();
    Page::factory()->recycle($site)->createOne(['visible_from' => '2026-10-26 00:30:00']);
    Page::factory()->recycle($site)->createOne(['visible_from' => '2026-10-25 23:30:00']);

    expect(BuildDefaultSiteStatsAction::run('this_week')->publishedCount)->toBe(1)
        ->and(BuildDefaultSiteStatsAction::run('this_month')->publishedCount)->toBe(2);
});

it('shows the calendar window, the current-count note and the site scope on the site statistics', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 10:00:00', 'Europe/London'));

    $first = Site::factory()->createOne(['name' => 'First Site']);
    Site::factory()->createOne(['name' => 'Second Site']);

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week', 'dashboardSiteId' => $first->getKey()])
        ->assertSee('Published pages: 26 Oct 2026 to 1 Nov 2026 (Europe/London).')
        ->assertSee(__('capell-admin::dashboard.window_current_counts'))
        ->assertSee(__('capell-admin::dashboard.scope_other_site', ['site' => 'First Site']));

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week'])
        ->assertSee(__('capell-admin::dashboard.scope_all_sites'));
});

it('does not name a site the person cannot access when describing site statistics scope', function (): void {
    Site::factory()->createOne();

    Livewire::test(SiteStatsOverviewFilamentWidget::class, ['dashboardPeriod' => 'this_week', 'dashboardSiteId' => 999999])
        ->assertSee(__('capell-admin::dashboard.scope_all_sites'));
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
