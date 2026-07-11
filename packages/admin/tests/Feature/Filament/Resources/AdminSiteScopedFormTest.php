<?php

declare(strict_types=1);
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Filament\Resources\Redirects\Pages\ManageRedirects;
use Capell\Admin\Tests\Fixtures\Models\SiteScopedFormTestUser;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

/** @param SupportCollection<int, int> $assignedSiteIds */
function createScopedUserForAdminSiteScopedFormTest(SupportCollection $assignedSiteIds): Authenticatable
{
    config()->set('auth.providers.users.model', SiteScopedFormTestUser::class);

    $user = new SiteScopedFormTestUser;
    $user->forceFill([
        'name' => 'Scoped Form User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    SiteScopedFormTestUser::rememberAssignedSiteIds((int) $user->getKey(), $assignedSiteIds);

    return $user->refresh();
}

beforeEach(function (): void {
    Gate::before(fn (): bool => true);
});

it('rejects creating page urls for unassigned sites', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherLanguage = Language::factory()->createOne();
    $otherSite = Site::factory()
        ->language($otherLanguage)
        ->has(SiteDomain::factory()->state(['language_id' => $otherLanguage->getKey()]))
        ->withTranslations($otherLanguage)
        ->create();
    $otherPage = Page::factory()->recycle($otherSite)->withTranslations($otherLanguage)->create();

    test()->actingAs(createScopedUserForAdminSiteScopedFormTest(collect([$assignedSite->getKey()])));

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->callAction('create', data: [
            'site_id' => $otherSite->getKey(),
            'language_id' => $otherLanguage->getKey(),
            'pageable_type' => $otherPage->getMorphClass(),
            'pageable_id' => $otherPage->getKey(),
            'url' => '/unassigned-page-url',
        ])
        ->assertHasFormErrors(['site_id']);
});

it('rejects creating redirects for unassigned sites', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherLanguage = Language::factory()->createOne();
    $otherSite = Site::factory()
        ->language($otherLanguage)
        ->has(SiteDomain::factory()->state(['language_id' => $otherLanguage->getKey()]))
        ->withTranslations($otherLanguage)
        ->create();

    test()->actingAs(createScopedUserForAdminSiteScopedFormTest(collect([$assignedSite->getKey()])));

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->callAction('create', data: [
            'site_id' => $otherSite->getKey(),
            'language_id' => $otherLanguage->getKey(),
            'url' => '/unassigned-redirect',
            'target_url' => '/target',
            'status_code' => 301,
        ])
        ->assertHasFormErrors(['site_id']);
});

it('rejects creating redirects when the language is not attached to the selected site', function (): void {
    $siteLanguage = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $site = Site::factory()
        ->language($siteLanguage)
        ->has(SiteDomain::factory()->state(['language_id' => $siteLanguage->getKey()]))
        ->withTranslations($siteLanguage)
        ->create();

    test()->actingAs(createScopedUserForAdminSiteScopedFormTest(collect([$site->getKey()])));

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->callAction('create', data: [
            'site_id' => $site->getKey(),
            'language_id' => $otherLanguage->getKey(),
            'url' => '/wrong-language-redirect',
            'target_url' => '/target',
            'status_code' => 301,
        ])
        ->assertHasFormErrors(['language_id']);
});

it('rejects creating redirects with unsafe target urls', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;

    test()->actingAs(createScopedUserForAdminSiteScopedFormTest(collect([$site->getKey()])));

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->callAction('create', data: [
            'site_id' => $site->getKey(),
            'language_id' => $language->getKey(),
            'url' => '/unsafe-target-redirect',
            'target_url' => 'javascript:alert(1)',
            'status_code' => 301,
        ])
        ->assertHasFormErrors(['target_url']);
});

it('rejects creating pages with a layout owned by another site', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $otherLayout = Layout::factory()->site($otherSite)->create();
    $type = Blueprint::factory()->page()->default()->create();

    test()->actingAs(createScopedUserForAdminSiteScopedFormTest(collect([$assignedSite->getKey()])));

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->mountAction('create')
        ->set('mountedActions.0.data.translations', [])
        ->set('mountedActions.0.data.site_id', $assignedSite->getKey())
        ->set('mountedActions.0.data.layout_id', $otherLayout->getKey())
        ->set('mountedActions.0.data.blueprint_id', $type->getKey())
        ->set('mountedActions.0.data.name', 'Cross-site layout page')
        ->callMountedAction()
        ->assertHasFormErrors(['layout_id']);
});

it('rejects creating pages with a parent owned by another site', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $assignedLayout = Layout::factory()->site($assignedSite)->create();
    $otherParent = Page::factory()->site($otherSite)->withTranslations()->create();
    $type = Blueprint::factory()->page()->default()->create();

    test()->actingAs(createScopedUserForAdminSiteScopedFormTest(collect([$assignedSite->getKey()])));

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->mountAction('create')
        ->set('mountedActions.0.data.translations', [])
        ->set('mountedActions.0.data.site_id', $assignedSite->getKey())
        ->set('mountedActions.0.data.parent_id', $otherParent->getKey())
        ->set('mountedActions.0.data.layout_id', $assignedLayout->getKey())
        ->set('mountedActions.0.data.blueprint_id', $type->getKey())
        ->set('mountedActions.0.data.name', 'Cross-site parent page')
        ->callMountedAction()
        ->assertHasFormErrors(['parent_id']);
});
