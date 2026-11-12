<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Admin\Actions\Dashboard\ResolveFirstRunStateAction;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\GuidedSetup;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\FirstRun\MarkFreshInstallSitesAction;
use Capell\Core\Actions\FirstRun\PublishGuidedSetupSiteAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('capell-admin.dashboard.guided_setup.redirect', true);
});

/** A freshly installed site, built as the install builds it: nothing set up yet. */
function guidedSetupSite(): Site
{
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey(), 'name' => 'My Laravel App']);
    CreateDefaultPagesAction::run($site->refresh(), $site->languages);
    // The install's completion marks the sites a first install made.
    MarkFreshInstallSitesAction::run();

    return $site->refresh();
}

/** The installer's pages beside the home page: not found, maintenance and welcome. */
const GUIDED_SETUP_INSTALLER_EXTRA_PAGES = 3;

/** @return array<int, Page> the pages guided setup added, without the install's own pages */
function guidedSetupWrittenPages(Site $site): array
{
    return Page::query()->where('site_id', $site->getKey())->with('translations')->get()
        ->reject(fn (Page $page): bool => in_array($page->translations->first()?->meta['slug'] ?? null, ['/', 'not-found', 'maintenance', 'welcome'], true))
        ->values()->all();
}

it('sends an administrator of a new site from the dashboard to guided setup', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();

    Livewire::test(CapellDashboard::class)->assertRedirect(GuidedSetup::getUrl());
});

it('sends one redirect when a checklist restore and guided setup are both due, restore first', function (): void {
    $this->actingAsAdmin();
    $user = $this->authenticatedUser();
    guidedSetupSite();
    $store = resolve(AdminWorkspacePreferenceStore::class);
    $store->setFirstRunChecklistHidden($user, true);

    // The restore redirect to the plain dashboard comes first, and setup is not also asked for in the same response.
    Livewire::withQueryParams([CapellDashboard::SHOW_FIRST_RUN_CHECKLIST_QUERY => '1'])
        ->test(CapellDashboard::class)
        ->assertRedirect(CapellDashboard::getUrl());

    expect($store->firstRunChecklistHidden($user->refresh()))->toBeFalse();

    // The plain dashboard address then sends the person on to guided setup.
    Livewire::withQueryParams([])->test(CapellDashboard::class)->assertRedirect(GuidedSetup::getUrl());
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
    $site->forceFill(['meta' => [...($site->meta ?? []), 'install_suite' => 'marketing']])->save();

    $page = Livewire::test(GuidedSetup::class);

    expect(collect($page->instance()->presets())->pluck('key')->all())->toContain('service-business', 'shop-front', 'studio');
    $page->assertSet('data.presetKey', 'service-business')
        ->assertSet('data.siteName', 'My Laravel App')
        ->assertSee(__('capell-admin::setup.step_business'))
        ->assertSee(__('capell-admin::setup.skip'));
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
        ->and($site->pages()->count())->toBe(3 + GUIDED_SETUP_INSTALLER_EXTRA_PAGES)
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

    expect($site->refresh()->pages()->count())->toBe(1 + GUIDED_SETUP_INSTALLER_EXTRA_PAGES);
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
        ->and($site->pages()->count())->toBe(1 + GUIDED_SETUP_INSTALLER_EXTRA_PAGES);
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

    expect(collect(guidedSetupWrittenPages($site))->contains(fn (Page $created): bool => $created->publishVisibilityState() === PublishVisibilityStateEnum::published))->toBeFalse();
});

function liveClientSite(): Site
{
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['name' => 'Client Co']);
    Page::factory()->home()->site($site)->createOne(['visible_from' => now()->subMonth()]);

    return $site;
}

it('refuses a browser that points setup at another site', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();
    $live = liveClientSite();

    $page = Livewire::test(GuidedSetup::class);

    expect(fn () => $page->set('siteId', $live->getKey()))->toThrow(Exception::class, 'Cannot update locked property')
        ->and(fn () => $page->set('created', true))->toThrow(Exception::class, 'Cannot update locked property');

    expect($live->refresh()->name)->toBe('Client Co')
        ->and(data_get($live->meta, 'guided_setup'))->toBeNull();
});

it('does nothing the second time Create pages is sent, so edits made in between are kept', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $page = Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true);

    $site->refresh()->update(['name' => 'Renamed by a person']);

    $page->call('createPages')
        ->assertNotified(__('capell-admin::setup.create_failed', ['reason' => __('capell-admin::setup.create_refused_already_set_up')]));

    expect($site->refresh()->name)->toBe('Renamed by a person');
});

it('refuses to publish before the pages have been created', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    // What setup stores is present, so only the check that the pages are waiting to be published can refuse.
    $meta = $site->meta ?? [];
    data_set($meta, 'guided_setup.preset_key', 'service-business');
    data_set($meta, 'guided_setup.page_slugs', ['about']);
    $site->forceFill(['meta' => $meta])->save();

    Livewire::test(GuidedSetup::class)->call('publishSite')->assertForbidden();

    expect(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeFalse();
});

it('refuses a replayed publish once the site is live, so pages taken down since stay private', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $page = Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true)
        ->call('publishSite')
        ->assertSet('sitePublished', true);
    $published = $page->instance();

    $about = Page::query()->where('site_id', $site->getKey())->notHomePage()->with('translations')->get()
        ->first(fn (Page $created): bool => ($created->translations->first()?->meta['slug'] ?? null) === 'about');
    throw_unless($about instanceof Page, LogicException::class, 'Setup should have created the About page.');
    $about->update(['visible_from' => PublishSentinel::draftValue()]);

    // The same component state sent again, as a saved snapshot would be.
    // The page itself is closed once the site is live; the publish step must refuse on its own as well,
    // so the same component state sent again, as a saved snapshot would be, cannot republish anything.
    $page->call('publishSite')->assertForbidden();
    expect(fn () => $published->publishSite())->toThrow(HttpException::class);

    expect($about->refresh()->publishVisibilityState())->not->toBe(PublishVisibilityStateEnum::published);
});

it('reads an uploaded logo from the local disk wherever that disk is rooted', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    // Laravel's default local disk before private files moved under storage/app/private.
    config()->set('filesystems.disks.local.root', storage_path('app'));
    Storage::forgetDisk('local');
    $before = Storage::disk('local')->files('guided-setup');

    try {
        $page = Livewire::test(GuidedSetup::class)
            ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about'], 'logo' => UploadedFile::fake()->image('logo.png')])
            ->call('createPages')
            ->assertHasNoFormErrors()
            ->assertSet('created', true);

        // Held back until the site is published, so it never reaches a live site early.
        expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(0);

        $page->call('publishSite')->assertSet('sitePublished', true);
    } finally {
        Storage::disk('local')->delete(array_diff(Storage::disk('local')->files('guided-setup'), $before));
    }

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(1);
});

it('tells the person what to do next without showing internal error text when creation breaks', function (): void {
    Exceptions::fake();
    $this->actingAsAdmin();
    guidedSetupSite();
    Site::updating(fn () => throw new RuntimeException('SQLSTATE[HY000]: secret table detail'));

    Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental'])
        ->call('createPages')
        ->assertSet('created', false)
        ->assertNotified(__('capell-admin::setup.create_failed', ['reason' => __('capell-admin::setup.create_failed_unknown')]))
        ->assertDontSee('secret table detail');

    Exceptions::assertReported(RuntimeException::class);
});

it('opens again for a site whose pages are waiting to be published, and publishes them from what setup stored', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true);

    $this->get(GuidedSetup::getUrl())->assertOk();

    Livewire::test(GuidedSetup::class)
        ->assertSet('created', true)
        ->assertSet('createdPageCount', 2)
        ->assertSet('siteId', $site->getKey())
        ->call('publishSite')
        ->assertSet('sitePublished', true);

    expect($site->refresh()->pages()->get()->every(fn (Page $created): bool => $created->publishVisibilityState() === PublishVisibilityStateEnum::published))->toBeTrue();
});

it("does not take the installer's public home page as a sign the site has gone live", function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();

    ResolveFirstRunStateAction::run($this->authenticatedUser());

    expect(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeFalse()
        ->and(GuidedSetup::isOwed())->toBeTrue();
});

it('never reads a logo path the browser made up, so no server file can be copied into the site', function (string $path): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $secret = storage_path('app/guided-setup-secret.txt');
    file_put_contents($secret, 'APP_KEY=secret');

    try {
        $page = Livewire::test(GuidedSetup::class)
            ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']]);

        try {
            $page->set('data.logo', ['x' => $path])->call('createPages');
        } catch (Throwable) {
            // Refusing the state outright is as good as ignoring it.
        }
    } finally {
        @unlink($secret);
    }

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(0)
        ->and(Media::query()->where('file_name', 'like', '%secret%')->orWhere('file_name', 'like', '%.env%')->exists())->toBeFalse();
})->with([
    'traversal to the environment file' => ['../../../.env'],
    'traversal to a private file' => ['../guided-setup-secret.txt'],
    'absolute path' => ['/etc/hosts'],
]);

it('records the launch of a site whose setup was skipped once a person has edited its public home page', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    Livewire::test(GuidedSetup::class)->call('skip');
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $home->translations->first()?->update(['content' => '<p>Our own homepage</p>']);

    ResolveFirstRunStateAction::run($this->authenticatedUser());

    expect(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeTrue();
});

it('keeps the placeholder home page working after setup is left before publishing, and offers Publish on the dashboard', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $placeholder = (string) $home->translations->first()?->content;

    Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true);

    $home->refresh()->load('translations');

    expect($home->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and((string) $home->translations->first()?->content)->toBe($placeholder);

    // The dashboard opens, with Publish offered rather than forced.
    Livewire::test(CapellDashboard::class)->assertNoRedirect()->assertNotified(__('capell-admin::setup.awaiting_publication'));
    Livewire::test(GuidedSetup::class)->assertSet('created', true)->assertSee(__('capell-admin::setup.open_home'));
});

it("writes the person's home page over the placeholder when they publish", function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $placeholder = (string) $home->translations->first()?->content;

    Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->call('publishSite')
        ->assertSet('sitePublished', true);

    $home->refresh()->load('translations');

    expect((string) $home->translations->first()?->content)->not->toBe($placeholder)->not->toBe('')
        ->and(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeTrue();

    Livewire::test(CapellDashboard::class)->assertNoRedirect();
});

function createGuidedSetupPages(): void
{
    Livewire::test(GuidedSetup::class)
        ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about']])
        ->call('createPages')
        ->assertSet('created', true);
}

it('lets an editor who may not publish use the dashboard after the pages are created, without offering Publish', function (): void {
    $this->actingAsAdmin();
    guidedSetupSite();
    createGuidedSetupPages();
    app()->instance(AuthorizesPublicationTransition::class, new readonly class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return false;
        }
    });

    Livewire::test(CapellDashboard::class)->assertNoRedirect()->assertNotNotified(__('capell-admin::setup.awaiting_publication'));
});

it('does not trap anyone on setup when the chosen page set is removed after the pages are created', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    createGuidedSetupPages();
    $meta = $site->refresh()->meta;
    data_set($meta, 'guided_setup.preset_key', 'a-removed-page-set');
    $site->forceFill(['meta' => $meta])->save();

    Livewire::test(CapellDashboard::class)->assertNoRedirect();
    $this->get(CapellDashboard::getUrl())->assertOk();
    $this->get(GuidedSetup::getUrl())->assertOk();
});

it('stops offering Publish and records the go-live once the person edits the placeholder home page', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    createGuidedSetupPages();
    $site->pages()->homePage()->with('translations')->firstOrFail()->translations->first()?->update(['content' => '<p>Written by the person</p>']);

    Livewire::test(CapellDashboard::class)->assertNoRedirect()->assertNotNotified(__('capell-admin::setup.awaiting_publication'));
    $this->get(GuidedSetup::getUrl())->assertForbidden();

    expect(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeTrue()
        ->and(collect(guidedSetupWrittenPages($site))->every(fn (Page $page): bool => $page->publishVisibilityState() === PublishVisibilityStateEnum::draft))->toBeTrue();
});

it('opens the dashboard for a site whose home page holds blocks, without taking it for the placeholder', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $home->forceFill(['content_structure_override' => ContentStructure::Blocks->value])->save();
    $home->translations->first()?->update(['content' => [['type' => 'text', 'data' => ['content' => '<p>Blocks</p>']]]]);

    $this->get(CapellDashboard::getUrl())->assertOk();
    Livewire::test(CapellDashboard::class)->assertNoRedirect();
});

it('says the site is published but the logo was not added, and adds it on a second try', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    $before = Storage::disk('local')->files('guided-setup');

    try {
        $page = Livewire::test(GuidedSetup::class)
            ->fillForm(['presetKey' => 'service-business', 'siteName' => 'Hartley Dental', 'pageSlugs' => ['about'], 'logo' => UploadedFile::fake()->image('logo.png')])
            ->call('createPages');
        config()->set('media-library.disk_name', 'a-disk-that-does-not-exist');

        $page->call('publishSite')
            ->assertSet('sitePublished', true)
            ->assertSet('logoPending', true)
            ->assertNotified(__('capell-admin::setup.logo_not_attached'));

        expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(0)
            ->and(array_diff(Storage::disk('local')->files('guided-setup'), $before))->toHaveCount(1);

        config()->set('media-library.disk_name', 'public');
        $page->call('retryLogo')->assertSet('logoPending', false)->assertNotified(__('capell-admin::setup.logo_attached'));
    } finally {
        Storage::disk('local')->delete(array_diff(Storage::disk('local')->files('guided-setup'), $before));
    }

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(1);
});

it('publishes once: a second publish of the same setup changes nothing and moves no logo twice', function (): void {
    $this->actingAsAdmin();
    $site = guidedSetupSite();
    createGuidedSetupPages();
    PublishGuidedSetupSiteAction::run($site->refresh(), $this->authenticatedUser());

    expect(fn (): int => PublishGuidedSetupSiteAction::run($site->refresh(), $this->authenticatedUser()))
        ->toThrow(GuidedSetupRefusedException::class);
});
