<?php

declare(strict_types=1);

use Capell\Admin\Actions\Metrics\ReadSiteAdminMetricSeriesAction;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Filament\Pages\SiteAdminMetricsPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
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

it('renders a stable metrics evidence wrapper for an authorized admin', function (): void {
    Permission::create(['name' => 'View:SiteAdminMetricsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SiteAdminMetricsPage');

    Livewire::test(SiteAdminMetricsPage::class)
        ->assertSuccessful()
        ->assertSeeHtml('data-testid="capell-site-admin-metrics"');
});
