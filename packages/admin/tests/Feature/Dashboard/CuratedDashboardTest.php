<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDashboardAttentionAction;
use Capell\Admin\Filament\Pages\AnalyticsDashboard;
use Capell\Admin\Filament\Pages\DashboardToolsPage;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('bounds attention findings to three affected pages in the selected accessible site', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    test()->actingAs($actor);
    $selected = Site::factory()->createOne();
    $other = Site::factory()->createOne();
    $pages = Page::factory()->count(4)->create(['site_id' => $selected->id]);
    foreach ($pages as $page) {
        $page->pageUrls()->update(['status' => false]);
    }

    $foreign = Page::factory()->createOne(['site_id' => $other->id]);
    $foreign->pageUrls()->update(['status' => false]);

    $findings = BuildDashboardAttentionAction::run($actor, $selected->id);

    expect($findings)->toHaveCount(3)
        ->and(array_column($findings, 'recordLabel'))->not->toContain($foreign->name);
});

it('does not disclose setup issues in an unassigned site through a dashboard filter', function (): void {
    $owned = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = test()->createUser();
    $actor->assignedSiteIds = collect([$owned->id]);
    Permission::findOrCreate('ViewAny:Page', 'web');
    $actor->givePermissionTo('ViewAny:Page');
    test()->actingAs($actor);
    $page = Page::factory()->createOne(['site_id' => $foreign->id]);
    $page->pageUrls()->update(['status' => false]);

    expect(BuildDashboardAttentionAction::run($actor, $foreign->id))->toBe([]);
});

it('denies specialist dashboards when none of their widgets can be viewed', function (): void {
    Site::factory()->createOne();
    test()->actingAsUser();

    test()->get(AnalyticsDashboard::getUrl())->assertForbidden();
    test()->get(DashboardToolsPage::getUrl())->assertForbidden();
});

it('preserves analytics filter context on the specialist route', function (): void {
    Site::factory()->createOne();
    test()->actingAsAdmin();
    $filters = ['date_range' => 'this_month', 'language' => 'en'];

    test()->get(AnalyticsDashboard::getUrl(['filters' => $filters]))->assertOk();
    expect((new AnalyticsDashboard)->getWidgets())->toHaveCount(4);
});

it('hides analytics access when all performance widgets are disabled', function (): void {
    Site::factory()->createOne();
    test()->actingAsAdmin();
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = array_replace($settings->enabled_widgets, array_fill_keys([
        'analytics_overview', 'analytics_trend', 'analytics_insights', 'analytics_recent_activity',
    ], false));
    $settings->save();

    expect(AnalyticsDashboard::canAccess())->toBeFalse();
    test()->get(AnalyticsDashboard::getUrl())->assertForbidden();
});
