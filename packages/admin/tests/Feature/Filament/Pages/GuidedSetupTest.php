<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\GuidedSetup;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('capell-admin.dashboard.guided_setup.redirect', true);
});

/** A freshly installed site: the installer's draft home page under the slug "/", nothing set up yet. */
function guidedSetupSite(): Site
{
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey(), 'name' => 'My Laravel App']);
    $home = Page::factory()->home()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    Translation::factory()->createOne([
        'translatable_type' => $home->getMorphClass(),
        'translatable_id' => $home->getKey(),
        'language_id' => $site->language_id,
        'meta' => ['slug' => '/'],
    ]);

    return $site->refresh();
}

it('sends an administrator of a new site from the dashboard to guided setup', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();

    Livewire::test(CapellDashboard::class)->assertRedirect(GuidedSetup::getUrl());
});

it('keeps the dashboard for a person who may not edit sites', function (): void {
    $this->actingAsUser();
    guidedSetupSite();

    expect(GuidedSetup::canAccess())->toBeFalse();

    Livewire::test(CapellDashboard::class)->assertNoRedirect();
    $this->get(GuidedSetup::getUrl())->assertForbidden();
});

it('keeps the dashboard once the site has been set up or skipped', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();

    Livewire::test(GuidedSetup::class)->call('skip');

    expect(GuidedSetup::canAccess())->toBeFalse()
        ->and(data_get($site->refresh()->meta, 'guided_setup.skipped_at'))->not->toBeNull();

    Livewire::test(CapellDashboard::class)->assertNoRedirect();
});

it('offers every installed page set and starts from the one matching the install suite', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $site->forceFill(['meta' => ['install_suite' => 'marketing']])->save();

    $page = Livewire::test(GuidedSetup::class);

    expect(collect($page->instance()->presets())->pluck('key')->all())->toContain('service-business', 'shop-front', 'studio');
    $page->assertSet('data.presetKey', 'service-business')
        ->assertSet('data.siteName', 'My Laravel App')
        ->assertSee(__('capell-admin::setup.step_business'));
});

it('previews the answers as they are typed', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();

    Livewire::test(GuidedSetup::class)
        ->set('data.siteName', 'Hartley Dental')
        ->set('data.tagline', 'Family dentist in Leeds')
        ->set('data.primaryColour', '#0f766e')
        ->assertSee('Hartley Dental')
        ->assertSee('Family dentist in Leeds')
        ->assertSee('#0f766e', false);
});

it('lists the pages of the chosen page set, all ticked, and follows a change of set', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();

    $page = Livewire::test(GuidedSetup::class)
        ->assertSet('data.pageSlugs', ['about', 'services', 'reviews', 'contact'])
        ->set('data.presetKey', 'studio')
        ->assertSet('data.pageSlugs', ['work', 'services', 'about', 'contact']);

    expect(array_keys($page->instance()->pageOptions()))->toBe(['home', 'work', 'services', 'about', 'contact']);
});

it('creates the chosen pages as private drafts and offers to open the homepage or publish', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();

    Livewire::test(GuidedSetup::class)
        ->fillForm([
            'presetKey' => 'service-business',
            'siteName' => 'Hartley Dental',
            'tagline' => 'Family dentist in Leeds',
            'pageSlugs' => ['about', 'contact'],
        ])
        ->call('createPages')
        ->assertHasNoFormErrors()
        ->assertSet('created', true)
        ->assertSet('createdPageCount', 3)
        ->assertSee(__('capell-admin::setup.created'))
        ->assertSee(__('capell-admin::setup.open_home'));

    $site->refresh();

    expect($site->name)->toBe('Hartley Dental')
        ->and($site->pages()->count())->toBe(3)
        ->and($site->pages()->homePage()->count())->toBe(1)
        ->and(data_get($site->meta, 'guided_setup.completed_at'))->not->toBeNull();
});

it('requires a site name before anything is created', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();

    Livewire::test(GuidedSetup::class)
        ->fillForm(['siteName' => ''])
        ->call('createPages')
        ->assertHasFormErrors(['siteName' => 'required']);

    expect($site->refresh()->pages()->count())->toBe(1);
});

it('reports a failed creation and changes nothing', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();

    Livewire::test(GuidedSetup::class)
        ->fillForm(['primaryColour' => '#0f766e'])
        ->set('data.primaryColour', 'teal')
        ->call('createPages')
        ->assertSet('created', false);

    expect($site->refresh()->name)->toBe('My Laravel App')
        ->and($site->pages()->count())->toBe(1);
});

it('says on the review step how many pages will be created and that they stay private', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();

    $body = Livewire::test(GuidedSetup::class)->instance()->reviewBody();

    expect($body)->toContain('5')
        ->and($body)->toBe((string) __('capell-admin::setup.review_body', ['count' => 5, 'extras' => '']));
});

it('publishes every created page when the person confirms', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $page = Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true);

    $page->call('publishSite')->assertSet('sitePublished', true);

    expect($site->refresh()->pages()->get()->every(fn (Page $created): bool => $created->publishVisibilityState() === PublishVisibilityStateEnum::published))->toBeTrue();
});

it('names the problem and leaves the pages private when publishing is refused', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $page = Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true);
    app()->instance(AuthorizesPublicationTransition::class, new readonly class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return false;
        }
    });

    $page->call('publishSite')->assertSet('sitePublished', false);

    expect($site->refresh()->pages()->get()->contains(fn (Page $created): bool => $created->publishVisibilityState() === PublishVisibilityStateEnum::published))->toBeFalse();
});
