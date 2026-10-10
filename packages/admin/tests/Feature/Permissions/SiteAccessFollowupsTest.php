<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BulkMovePagesAction;
use Capell\Admin\Actions\PageTree\LoadPageTreeBranchAction;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

it('uses installation state for both dashboard paths when an actor has no assigned sites', function (): void {
    Site::factory()->create();
    test()->actingAs(User::factory()->create());
    $dashboard = new CapellDashboard;

    expect(new ReflectionMethod($dashboard, 'dashboardEnum')->invoke($dashboard))->toBe(DashboardEnum::Main)
        ->and($dashboard->getWidgets())->toBe(array_values(array_unique([
            ...CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main),
            ...CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::MarketingStudio),
        ], SORT_REGULAR)));
});

it('does not send a zero site actor to site installation when creating a page', function (): void {
    Site::factory()->withTranslations()->create();
    Blueprint::factory()->page()->default()->create();
    $actor = User::factory()->create();
    foreach (['Create:Page', 'ViewAny:Page'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }

    test()->actingAs($actor);
    Livewire::test(CreatePage::class)->assertSuccessful()->assertNoRedirect();
});

it('purges every site after a non global actor saves global theme settings', function (): void {
    $alpha = Site::factory()->create();
    $beta = Site::factory()->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$alpha->id]);
    Permission::findOrCreate('View:SettingsPage', 'web');
    $actor->givePermissionTo('View:SettingsPage');
    test()->actingAs($actor);
    resolve(ThemeStudioSettings::class);
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    Livewire::test(SettingsPage::class)->assertSuccessful()->call('save')->assertHasNoFormErrors();

    Event::assertDispatched(FrontendSurrogateKeysInvalidated::class);
    expect(Event::dispatched(FrontendSurrogateKeysInvalidated::class)->first()[0]->surrogateKeys)->toBe(['site-' . $alpha->id, 'site-' . $beta->id]);
});

it('loads a branch and its children for the supplied actor independently of request authentication', function (): void {
    $alpha = Site::factory()->create();
    $beta = Site::factory()->create();
    $parent = Page::factory()->site($alpha)->create();
    $child = Page::factory()->site($alpha)->parent($parent)->create();
    Page::factory()->site($beta)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$alpha->id]);
    Permission::findOrCreate('View:Page', 'web');
    $actor->givePermissionTo('View:Page');
    auth()->logout();
    $action = new LoadPageTreeBranchAction;

    expect($action->handle($actor, $parent->id)->modelKeys())->toBe([$child->id])
        ->and($action->hasVisibleChildren($actor, $parent))->toBeTrue();
    $otherActor = User::factory()->create();
    $otherActor->assignedSiteIds = collect([$beta->id]);
    $otherActor->givePermissionTo('View:Page');

    test()->actingAs($otherActor);
    expect($action->handle($actor, $parent->id)->modelKeys())->toBe([$child->id])
        ->and($action->hasVisibleChildren($otherActor, $parent))->toBeFalse();
});

it('checks redirect collisions for the explicit move actor without request authentication', function (): void {
    test()->actingAsAdmin();
    $actor = test()->authenticatedUser();
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->site($site)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations()->create();
    $alias = $page->pageUrls()->whereNull('type')->firstOrFail();
    $duplicate = PageUrl::withoutEvents(fn (): PageUrl => PageUrl::query()->create([
        'site_id' => $site->id, 'language_id' => $alias->language_id,
        'url' => $alias->url, 'type' => UrlTypeEnum::Redirect,
        'pageable_id' => $parent->id, 'pageable_type' => $parent->getMorphClass(), 'is_manual' => true,
    ]));
    auth()->logout();

    $result = BulkMovePagesAction::run(new Collection([$page]), $parent, $actor, true);
    expect($result['failed_at'])->toBeNull()->and($result['moved'])->toBe(1)
        ->and($result['redirects'])->toBe(0)
        ->and(PageUrl::query()->where('url', $alias->url)->where('type', UrlTypeEnum::Redirect)->pluck('id')->all())->toBe([$duplicate->id]);
});

it('checks child visibility for the supplied actor without request authentication', function (): void {
    $site = Site::factory()->create();
    $parent = Page::factory()->site($site)->create();
    Page::factory()->site($site)->parent($parent)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$site->id]);
    Permission::findOrCreate('View:Page', 'web');
    $actor->givePermissionTo('View:Page');
    auth()->logout();

    expect((new LoadPageTreeBranchAction)->hasVisibleChildren($actor, $parent))->toBeTrue();
});
