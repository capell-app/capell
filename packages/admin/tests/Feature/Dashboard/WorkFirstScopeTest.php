<?php

declare(strict_types=1);

use Capell\Admin\Data\Dashboard\DashboardScopePreferenceData;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('restores personal scope and does not save a tampered site filter', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $user = test()->authenticatedUser();
    $store = resolve(AdminWorkspacePreferenceStore::class);
    $store->saveDashboardScope($user, new DashboardScopePreferenceData($site->id, null));

    Livewire::test(CapellDashboard::class)
        ->assertSet('filters.site_id', $site->id)
        ->set('filters.site_id', 'invalid');
    expect($store->dashboardScope($user)?->siteId)->toBe($site->id);
});

it('offers only languages belonging to the selected site', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $english = Language::factory()->createOne(['code' => 'en', 'name' => 'English', 'status' => true]);
    $french = Language::factory()->createOne(['code' => 'fr', 'name' => 'French', 'status' => true]);
    $site = Site::factory()->createOne(['language_id' => $english->id]);
    Site::factory()->createOne(['language_id' => $french->id]);
    $dashboard = new CapellDashboard;
    $dashboard->filters = ['site_id' => $site->id];

    $options = new ReflectionMethod($dashboard, 'workFirstLanguages')->invoke($dashboard);

    expect($options)->toHaveKey('en')->not->toHaveKey('fr');
});
