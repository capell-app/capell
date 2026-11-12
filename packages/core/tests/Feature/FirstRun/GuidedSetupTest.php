<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\FirstRun\AttachGuidedSetupLogoAction;
use Capell\Core\Actions\FirstRun\BuildGuidedSetupSpecAction;
use Capell\Core\Actions\FirstRun\CompleteGuidedSetupAction;
use Capell\Core\Actions\FirstRun\MarkFreshInstallSitesAction;
use Capell\Core\Actions\FirstRun\PublishGuidedSetupSiteAction;
use Capell\Core\Actions\FirstRun\RecordInstallSuiteAction;
use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Actions\FirstRun\SettleStrandedGuidedSetupAction;
use Capell\Core\Actions\FirstRun\SkipGuidedSetupAction;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Actions\SiteReplicatedAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\SiteSpecPresetData;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Exceptions\GuidedSetupLogoNotAttachedException;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Core\Support\FirstRun\GuidedSetupLogo;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecPresetRegistry;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

function guidedSetupAdmin(): User
{
    return test()->createUserWithRole('super_admin');
}

/** A person's edit of a page, as the editor records it. */
function personRevision(Page $page): void
{
    PageRevision::query()->create([
        'page_uuid' => $page->uuid,
        'version' => (int) PageRevision::query()->where('page_uuid', $page->uuid)->max('version') + 1,
        'actor_id' => guidedSetupAdmin()->getKey(),
        'summary' => 'Edited',
        'is_rollback' => false,
        'source' => 'edit',
        'occurred_at' => now(),
    ]);
}

/**
 * A freshly installed site, built as the install builds it: the admin setup's default pages,
 * then the install's completion, which marks the sites a first install made.
 */
function installedSite(): Site
{
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey(), 'name' => 'My Laravel App']);
    CreateDefaultPagesAction::run($site->refresh(), $site->languages);
    MarkFreshInstallSitesAction::run();

    return $site->refresh();
}

/** The installer's pages beside the home page: not found, maintenance and welcome. */
const INSTALLER_EXTRA_PAGES = 3;

/** @return array<int, Page> the pages guided setup added, without the install's own pages */
function guidedSetupPages(Site $site): array
{
    return Page::query()->where('site_id', $site->getKey())->with('translations')->get()
        ->reject(fn (Page $page): bool => in_array($page->translations->first()?->meta['slug'] ?? null, ['/', 'not-found', 'maintenance', 'welcome'], true))
        ->values()->all();
}

function guidedInput(string ...$pageSlugs): GuidedSetupInputData
{
    return new GuidedSetupInputData(
        presetKey: 'service-business',
        siteName: 'Hartley Dental',
        tagline: 'Family dentist in Leeds',
        primaryColour: '#0f766e',
        pageSlugs: $pageSlugs,
    );
}

/** Stands in for the Navigation extension, which owns the menu part of a spec. */
function registerNavigationApplier(): void
{
    resolve(SiteSpecApplierRegistry::class)->register(new class implements SiteSpecApplier
    {
        public function key(): string
        {
            return 'navigation';
        }

        /** @param array<string, Page> $pagesBySlug */
        public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void {}
    });
}

it('leaves the menu out when the Navigation extension is not installed', function (): void {
    $spec = BuildGuidedSetupSpecAction::run(guidedInput('about'));

    expect($spec->navigations)->toBe([]);
});

it('lays a name, one-line description, colour and page choices over the chosen preset', function (): void {
    registerNavigationApplier();

    $spec = BuildGuidedSetupSpecAction::run(guidedInput('about', 'contact'));

    expect($spec->site->name)->toBe('Hartley Dental')
        ->and($spec->site->description)->toBe('Family dentist in Leeds')
        ->and($spec->theme->colors->primary)->toBe('#0f766e')
        ->and(array_map(fn ($page): string => $page->slug, $spec->pages))->toBe(['home', 'about', 'contact'])
        ->and($spec->navigations[0]->pageSlugs)->toBe(['home', 'about', 'contact'])
        ->and($spec->initialVisibility)->toBe('private');
});

it('always creates the home page, even when no page is ticked', function (): void {
    $spec = BuildGuidedSetupSpecAction::run(guidedInput());

    expect(array_map(fn ($page): string => $page->slug, $spec->pages))->toBe(['home']);
});

it('refuses a preset that is not registered', function (): void {
    BuildGuidedSetupSpecAction::run(new GuidedSetupInputData(presetKey: 'missing', siteName: 'Hartley Dental'));
})->throws(InvalidArgumentException::class);

it('refuses a colour that is not a hex value', function (): void {
    BuildGuidedSetupSpecAction::run(new GuidedSetupInputData(presetKey: 'service-business', siteName: 'Hartley Dental', primaryColour: 'teal'));
})->throws(ValidationException::class);

it('creates the chosen pages as private drafts on the existing site and records that setup is done', function (): void {
    $site = installedSite();
    $user = guidedSetupAdmin();

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeTrue();

    CompleteGuidedSetupAction::run(guidedInput('about', 'contact'), $site);

    $site->refresh();
    $slugs = $site->pages()->with('translations')->get()
        ->map(fn (Page $page): ?string => $page->translations->first()?->meta['slug'] ?? null)
        ->filter()->sort()->values()->all();

    expect($site->name)->toBe('Hartley Dental')
        ->and($slugs)->toBe(['/', 'about', 'contact', 'maintenance', 'not-found', 'welcome'])
        ->and(collect(guidedSetupPages($site))->every(fn (Page $page): bool => $page->publishVisibilityState() !== PublishVisibilityStateEnum::published))->toBeTrue()
        ->and($site->pages()->homePage()->firstOrFail()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and(data_get($site->meta, 'guided_setup.preset_key'))->toBe('service-business')
        ->and(data_get($site->meta, 'guided_setup.completed_at'))->not->toBeNull()
        ->and(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse();
});

it('holds the uploaded logo back from the site until it is published', function (): void {
    $site = installedSite();
    Storage::fake(GuidedSetupLogo::DISK);
    Storage::disk(GuidedSetupLogo::DISK)->put('guided-setup/logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true));

    CompleteGuidedSetupAction::run(guidedInput(), $site, 'guided-setup/logo.png');

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(0)
        ->and(data_get($site->meta, 'guided_setup.logo'))->toBe('guided-setup/logo.png');
});

it('accepts a held logo only from inside the guided setup directory and only as an image', function (string $stored): void {
    Storage::fake(GuidedSetupLogo::DISK);
    Storage::disk(GuidedSetupLogo::DISK)->put('secret.txt', 'APP_KEY=secret');
    Storage::disk(GuidedSetupLogo::DISK)->put('guided-setup/notes.txt', 'not an image');
    Storage::disk(GuidedSetupLogo::DISK)->put('outside.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true));

    expect(GuidedSetupLogo::path($stored))->toBeNull();
})->with(['guided-setup/../secret.txt', '../secret.txt', 'secret.txt', '/etc/hosts', 'guided-setup/notes.txt', 'outside.png', 'guided-setup/../outside.png', '']);

it('leaves the site untouched when the spec is refused', function (): void {
    $site = installedSite();
    $pages = $site->pages()->count();

    try {
        CompleteGuidedSetupAction::run(new GuidedSetupInputData(presetKey: 'service-business', siteName: 'Hartley Dental', primaryColour: 'teal'), $site);
    } catch (ValidationException) {
        // Expected: the colour is not a hex value.
    }

    expect($site->refresh()->name)->toBe('My Laravel App')
        ->and($site->pages()->count())->toBe($pages)
        ->and(data_get($site->meta, 'guided_setup.completed_at'))->toBeNull()
        ->and(data_get($site->meta, 'guided_setup.preset_key'))->toBeNull();
});

it('stops asking once setup is skipped', function (): void {
    $site = installedSite();
    $user = guidedSetupAdmin();

    SkipGuidedSetupAction::run($site);

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse()
        ->and($site->pages()->count())->toBe(1 + INSTALLER_EXTRA_PAGES);
});

it("still asks while the only public home page is the install's untouched placeholder", function (): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->firstOrFail();

    expect($home->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeTrue();
});

it('never offers setup to a site the install did not mark, such as one installed before the mark existed', function (): void {
    $site = installedSite();
    $meta = $site->meta ?? [];
    Arr::forget($meta, ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY);
    $site->forceFill(['meta' => $meta])->save();

    expect(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::refusalFor($site->refresh()))->toBe(ResolveGuidedSetupStateAction::REASON_SET_UP);

    expect(fn (): mixed => CompleteGuidedSetupAction::run(guidedSetupInput(), $site))->toThrow(GuidedSetupRefusedException::class)
        ->and(data_get($site->refresh()->meta, 'guided_setup.completed_at'))->toBeNull();
});

it('never asks about a site that was built from an imported site spec', function (): void {
    $site = installedSite();
    $site->forceFill(['meta' => ['site_spec_hash' => 'abc123']])->save();

    expect(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse();
});

it('does not ask when there is no site yet', function (): void {
    expect(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse();
});

it('remembers the install suite once and offers it to guided setup', function (): void {
    $site = installedSite();

    RecordInstallSuiteAction::run('marketing');
    RecordInstallSuiteAction::run('blog');

    expect(data_get($site->refresh()->meta, 'install_suite'))->toBe('marketing')
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->suiteKey)->toBe('marketing');
});

it('records nothing when the install had no suite', function (): void {
    $site = installedSite();

    RecordInstallSuiteAction::run(null);
    RecordInstallSuiteAction::run('  ');

    expect(data_get($site->refresh()->meta, 'install_suite'))->toBeNull();
});

it('reuses the installer home page instead of creating a second one', function (): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->firstOrFail();

    CompleteGuidedSetupAction::run(guidedInput('about'), $site);

    expect($site->pages()->homePage()->count())->toBe(1)
        ->and($site->pages()->count())->toBe(2 + INSTALLER_EXTRA_PAGES)
        ->and($site->pages()->homePage()->firstOrFail()->is($home))->toBeTrue();
});

it('applies every shipped preset to a freshly installed site', function (string $presetKey): void {
    $site = installedSite();
    $preset = resolve(SiteSpecPresetRegistry::class)->get($presetKey);
    $slugs = array_column($preset?->spec['pages'] ?? [], 'slug');

    CompleteGuidedSetupAction::run(new GuidedSetupInputData(presetKey: $presetKey, siteName: 'Hartley Dental', pageSlugs: $slugs), $site);

    expect($site->refresh()->pages()->count())->toBe(count($slugs) + INSTALLER_EXTRA_PAGES);
})->with(['service-business', 'shop-front', 'studio']);

it('keeps a site awaiting publication from the moment its pages are created until it is published', function (): void {
    $site = installedSite();
    $actor = guidedSetupAdmin();
    $state = resolve(ResolveGuidedSetupStateAction::class);

    expect($state->awaitingPublication($actor))->toBeNull();

    CompleteGuidedSetupAction::run(guidedInput(), $site);

    expect($state->awaitingPublication($actor)?->is($site))->toBeTrue();

    // A go-live recorded some other way does not take away Publish: only setup's own publish does.
    RecordSitePublishedAction::run((int) $site->getKey());

    expect($state->awaitingPublication($actor)?->is($site))->toBeTrue();

    $meta = $site->refresh()->meta ?? [];
    data_set($meta, 'guided_setup.published_at', now()->toIso8601String());
    $site->forceFill(['meta' => $meta])->save();

    expect($state->awaitingPublication($actor))->toBeNull();
});

it('stops taking the home page as the placeholder once a person edits it, but not when the install changes it', function (): void {
    $site = installedSite();
    CompleteGuidedSetupAction::run(guidedInput('about'), $site);
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();

    // A package or seeder writing the home page is not a person's edit.
    $home->translations->first()?->update(['content' => '<p>Written by a package</p>']);

    expect(ResolveGuidedSetupStateAction::isPlaceholderHome($site->refresh(), $home->refresh()))->toBeTrue();

    editAsPerson($home, '<p>Written by the person</p>');

    expect(ResolveGuidedSetupStateAction::isPlaceholderHome($site, $home->refresh()))->toBeFalse();
});

it('does not take another home page with the same copy as the placeholder', function (): void {
    $site = installedSite();
    $mark = data_get($site->meta, ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY);
    $home = $site->pages()->homePage()->firstOrFail();

    expect($mark['home_uuid'] ?? null)->toBe((string) $home->uuid);

    $meta = $site->meta;
    data_set($meta, ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY . '.home_uuid', (string) Str::uuid());
    $site->forceFill(['meta' => $meta])->save();

    expect(ResolveGuidedSetupStateAction::isPlaceholderHome($site->refresh(), $home))->toBeFalse();
});

it('writes the spec home page over the placeholder and records the go-live when a private spec is published from the command line', function (): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $placeholder = (string) $home->translations->first()?->content;
    app()->instance(AuthorizesPublicationTransition::class, new readonly class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return true;
        }
    });
    $user = config('auth.providers.users.model')::factory()->createOne();
    $path = (string) tempnam(sys_get_temp_dir(), 'capell-guided-spec-');
    file_put_contents($path, json_encode(BuildGuidedSetupSpecAction::run(guidedInput('about'), $site)->toArray(), JSON_THROW_ON_ERROR));

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey(), '--publish' => true, '--user' => $user->email]);
        assert($command instanceof PendingCommand);
        $command->assertExitCode(0)->run();
    } finally {
        unlink($path);
    }

    $home->refresh()->load('translations');

    expect((string) $home->translations->first()?->content)->not->toBe($placeholder)
        ->and(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeTrue();
});

it('keeps the copy of a site it replicates free of setup and go-live state', function (): void {
    $site = installedSite();
    RecordSitePublishedAction::run((int) $site->getKey());

    $replica = SiteReplicatedAction::run($site->refresh(), ['name' => 'Copy']);

    expect(data_get($replica->meta, 'guided_setup'))->toBeNull()
        ->and(data_get($replica->meta, 'first_run.published_at'))->toBeNull()
        ->and(data_get($site->refresh()->meta, 'first_run.published_at'))->not->toBeNull();
});

it('never asks about a site that has gone live, even if its home page is private again', function (): void {
    $site = installedSite();
    $user = guidedSetupAdmin();

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeTrue();

    resolve(SitePublicationMilestones::class)->record((int) $site->getKey(), CarbonImmutable::now());

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::make()->awaitingPublication($user))->toBeNull();
});

it('keeps meta recorded since the site was loaded when setup is skipped from a stale copy', function (): void {
    $site = installedSite();
    $stale = Site::query()->findOrFail($site->getKey());
    RecordInstallSuiteAction::run('marketing');

    SkipGuidedSetupAction::run($stale);

    $meta = $site->refresh()->meta;

    expect(data_get($meta, 'install_suite'))->toBe('marketing')
        ->and(data_get($meta, 'guided_setup.skipped_at'))->toBeString();
});

it('refuses to skip setup on a site that has gone live', function (): void {
    $site = installedSite();
    resolve(SitePublicationMilestones::class)->record((int) $site->getKey(), CarbonImmutable::now());

    expect(fn (): mixed => SkipGuidedSetupAction::run($site))->toThrow(GuidedSetupRefusedException::class)
        ->and(data_get($site->refresh()->meta, 'guided_setup.skipped_at'))->toBeNull();
});

it('tells the first-run checklist and guided setup the same thing about a site being set up', function (): void {
    $site = installedSite();

    expect(ResolveGuidedSetupStateAction::isSetUp($site))->toBeFalse();

    SkipGuidedSetupAction::run($site);

    expect(ResolveGuidedSetupStateAction::isSetUp($site->refresh()))->toBeFalse();

    $site->forceFill(['meta' => [...($site->meta ?? []), 'guided_setup' => ['completed_at' => now()->toIso8601String()]]])->save();

    expect(ResolveGuidedSetupStateAction::isSetUp($site->refresh()))->toBeTrue();
});

function guidedSetupInput(): GuidedSetupInputData
{
    return new GuidedSetupInputData(presetKey: 'service-business', siteName: 'Harbour Plumbing', pageSlugs: ['about']);
}

it('refuses to run twice, so a repeated submit cannot overwrite edits made in between', function (): void {
    $site = installedSite();

    CompleteGuidedSetupAction::run(guidedSetupInput(), $site);

    $about = Page::query()->where('site_id', $site->getKey())->notHomePage()->with('translations')->get()
        ->first(fn (Page $page): bool => $page->translations->contains(fn (Translation $translation): bool => ($translation->meta['slug'] ?? null) === 'about'));
    throw_unless($about instanceof Page, LogicException::class, 'Setup should have created the About page.');
    $translation = $about->translations->first();
    throw_unless($translation instanceof Translation, LogicException::class, 'The About page should have a translation.');
    $translation->forceFill(['title' => 'Written by a person'])->save();

    expect(fn (): mixed => CompleteGuidedSetupAction::run(guidedSetupInput(), $site))
        ->toThrow(GuidedSetupRefusedException::class)
        ->and($translation->refresh()->title)->toBe('Written by a person');
});

it('refuses a live site, whatever the request says', function (): void {
    $site = installedSite();
    $site->pages()->get()->each(fn (Page $page) => $page->update(['visible_from' => now()->subDay()]));
    resolve(SitePublicationMilestones::class)->record((int) $site->getKey(), CarbonImmutable::now());

    expect(fn (): mixed => CompleteGuidedSetupAction::run(guidedSetupInput(), $site))
        ->toThrow(GuidedSetupRefusedException::class)
        ->and(data_get($site->refresh()->meta, 'guided_setup.completed_at'))->toBeNull()
        ->and($site->pages()->count())->toBe(1 + INSTALLER_EXTRA_PAGES);
});

it('does not owe setup to an unlaunched site that holds a page a person made, and refuses it if forced', function (): void {
    $site = installedSite();
    $about = Page::factory()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    personRevision($about);
    $user = guidedSetupAdmin();

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeTrue();

    expect(fn (): mixed => CompleteGuidedSetupAction::run(guidedSetupInput(), $site))
        ->toThrow(GuidedSetupRefusedException::class, 'already has pages or edits')
        ->and($site->pages()->count())->toBe(2 + INSTALLER_EXTRA_PAGES)
        ->and($about->refresh()->exists)->toBeTrue();
});

it('owes setup to a site the install marked, with only the pages the install made', function (): void {
    $site = installedSite();

    expect($site->pages()->count())->toBe(1 + INSTALLER_EXTRA_PAGES)
        ->and(ResolveGuidedSetupStateAction::isFreshInstall($site))->toBeTrue()
        ->and(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeTrue();
});

it('is not stopped by pages a package seeded, wherever they came from', function (): void {
    $site = installedSite();
    resolve(PageCreator::class)->createPage(['name' => 'Sitemap', 'translation' => ['slug' => 'sitemap']], $site, $site->languages);

    expect(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeTrue();
});

it("does not owe setup once a person has edited one of the install's other pages", function (): void {
    $site = installedSite();
    personRevision($site->pages()->notHomePage()->firstOrFail());

    expect(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeTrue()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse();
});

it('leaves the placeholder home page public and as it was after setup, and still holds nothing else back', function (): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $before = (string) $home->translations->first()?->content;
    registerNavigationApplier();

    $plan = CompleteGuidedSetupAction::run(guidedInput('about'), $site);
    $home->refresh()->load('translations');

    expect($home->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and((string) $home->translations->first()?->content)->toBe($before)
        ->and($plan->packagePartsHeld)->toBeFalse()
        ->and(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeFalse();
});

it('does not owe setup to a site whose home page a person has edited', function (): void {
    $site = installedSite();
    $home = Page::query()->where('site_id', $site->getKey())->homePage()->firstOrFail();
    PageRevision::query()->create([
        'page_uuid' => $home->uuid,
        'version' => 1,
        'actor_id' => guidedSetupAdmin()->getKey(),
        'summary' => 'Edited',
        'is_rollback' => false,
        'source' => 'edit',
        'occurred_at' => now(),
    ]);

    expect(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse();
});

it('remembers what is owed for the request and forgets it when setup finishes', function (): void {
    $site = installedSite();
    $user = guidedSetupAdmin();

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeTrue();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    ResolveGuidedSetupStateAction::run($user);
    ResolveGuidedSetupStateAction::run($user);

    expect($queries)->toBe(0);

    CompleteGuidedSetupAction::run(guidedSetupInput(), $site);

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse();
});

it('stores what was chosen so the pages can be published later', function (): void {
    $site = installedSite();

    CompleteGuidedSetupAction::run(guidedSetupInput(), $site);

    expect(data_get($site->refresh()->meta, 'guided_setup.preset_key'))->toBe('service-business')
        ->and(data_get($site->meta, 'guided_setup.page_slugs'))->toBe(['about']);
});

it('treats a home page a person switched to blocks as no longer the placeholder, without failing', function (): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $home->forceFill(['content_structure_override' => ContentStructure::Blocks->value])->save();
    $home->translations->first()?->update(['content' => [['type' => 'text', 'data' => ['content' => '<p>Blocks</p>']]]]);
    personRevision($home);

    expect(ResolveGuidedSetupStateAction::isPlaceholderHome($site->refresh(), $home->refresh()))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse();
});

it('marks a first install whose home page holds blocks, without failing the install', function (): void {
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey()]);
    CreateDefaultPagesAction::run($site->refresh(), $site->languages);
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $home->forceFill(['content_structure_override' => ContentStructure::Blocks->value])->save();
    $home->translations->first()?->update(['content' => [['type' => 'text', 'data' => ['content' => '<p>Blocks</p>']]]]);

    MarkFreshInstallSitesAction::run();

    expect(ResolveGuidedSetupStateAction::isPlaceholderHome($site->refresh(), $home->refresh()))->toBeTrue();
});

it('never throws while deciding about a home page of any shape', function (Closure $shape): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->with('translations')->firstOrFail();
    $shape($home);
    $admin = guidedSetupAdmin();
    $home = Page::withTrashed()->whereKey($home->getKey())->firstOrFail();

    expect(fn (): mixed => [
        ResolveGuidedSetupStateAction::isOwedTo($site->refresh()),
        ResolveGuidedSetupStateAction::refusalFor($site),
        ResolveGuidedSetupStateAction::isPlaceholderHome($site, $home),
        ResolveGuidedSetupStateAction::placeholderHome($site),
        ResolveGuidedSetupStateAction::untouchedSetupPages($site),
        ResolveGuidedSetupStateAction::awaitsPublication($site),
        ResolveGuidedSetupStateAction::make()->awaitingPublication($admin),
        ResolveGuidedSetupStateAction::make()->strandedSetup($admin),
        ResolveGuidedSetupStateAction::run($admin),
    ])->not->toThrow(Throwable::class);
})->with([
    'html' => [fn (Page $home): bool => (bool) $home->translations->first()?->update(['content' => '<p>Hello</p>'])],
    'blocks' => [function (Page $home): void {
        $home->forceFill(['content_structure_override' => ContentStructure::Blocks->value])->save();
        $home->translations->first()?->update(['content' => [['type' => 'text', 'data' => ['content' => '<p>Blocks</p>']]]]);
    }],
    'empty content' => [fn (Page $home): bool => (bool) $home->translations->first()?->update(['content' => null])],
    'missing translation' => [fn (Page $home): mixed => $home->translations()->forceDelete()],
    'soft-deleted translation' => [fn (Page $home): mixed => $home->translations()->delete()],
    'deleted home' => [fn (Page $home): ?bool => $home->delete()],
]);

/** The page setup created at the given slug. */
function createdPage(Site $site, string $slug): Page
{
    $page = collect(guidedSetupPages($site))->first(fn (Page $page): bool => ($page->translations->first()?->meta['slug'] ?? null) === $slug);

    return $page instanceof Page ? $page : throw new RuntimeException(sprintf('Setup created no page at [%s].', $slug));
}

/** Lets anyone publish, so these tests turn on what setup does rather than on a role's permissions. */
function allowPublishing(): void
{
    app()->instance(AuthorizesPublicationTransition::class, new readonly class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return true;
        }
    });
}

/** A copy of the service business page set under its own key, so a test can change or remove it after setup. */
function swapTestPreset(?Closure $change = null): void
{
    $spec = (resolve(SiteSpecPresetRegistry::class)->get('service-business') ?? throw new RuntimeException('The service business page set is missing.'))->spec;
    $registry = new SiteSpecPresetRegistry(app());

    if ($change instanceof Closure) {
        $registry->register(new SiteSpecPresetData(key: 'test-pages', label: 'Test pages', description: 'Test pages', spec: $change($spec)));
    }

    app()->instance(SiteSpecPresetRegistry::class, $registry);
}

it('publishes what setup created when the page set is removed after the pages are created', function (): void {
    $site = installedSite();
    allowPublishing();
    swapTestPreset(fn (array $spec): array => $spec);
    CompleteGuidedSetupAction::run(new GuidedSetupInputData(presetKey: 'test-pages', siteName: 'Hartley Dental', pageSlugs: ['about']), $site);
    swapTestPreset();

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect(collect(guidedSetupPages($site))->every(fn (Page $page): bool => $page->publishVisibilityState() === PublishVisibilityStateEnum::published))->toBeTrue()
        ->and(guidedSetupPages($site))->toHaveCount(1);
});

it('publishes what setup created when the page set gains a page after the pages are created', function (): void {
    $site = installedSite();
    allowPublishing();
    swapTestPreset(fn (array $spec): array => $spec);
    CompleteGuidedSetupAction::run(new GuidedSetupInputData(presetKey: 'test-pages', siteName: 'Hartley Dental', pageSlugs: ['about', 'team']), $site);
    swapTestPreset(function (array $spec): array {
        $about = collect($spec['pages'])->firstWhere('slug', 'about');
        $spec['pages'][] = [...Arr::except($about, ['url']), 'slug' => 'team', 'name' => 'Team', 'title' => 'Team', 'order' => 99];

        return $spec;
    });

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect(guidedSetupPages($site))->toHaveCount(1)
        ->and(guidedSetupPages($site)[0]->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('publishes only the created pages still private: a page the person deleted stays deleted', function (): void {
    $site = installedSite();
    allowPublishing();
    $admin = guidedSetupAdmin();
    CompleteGuidedSetupAction::run(guidedInput('about', 'contact'), $site);
    $pages = guidedSetupPages($site);
    $pages[0]->delete();

    PublishGuidedSetupSiteAction::run($site->refresh(), $admin);

    expect(Page::withTrashed()->whereKey($pages[0]->getKey())->firstOrFail()->trashed())->toBeTrue()
        ->and($pages[1]->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('reports a logo that could not be attached after publishing, and keeps the file so it can be tried again', function (): void {
    $site = installedSite();
    allowPublishing();
    Storage::disk(GuidedSetupLogo::DISK)->put('guided-setup/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    CompleteGuidedSetupAction::run(guidedInput('about'), $site, 'guided-setup/logo.png');
    config()->set('media-library.disk_name', 'a-disk-that-does-not-exist');

    expect(fn (): int => PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin()))->toThrow(GuidedSetupLogoNotAttachedException::class)
        ->and(data_get($site->refresh()->meta, 'guided_setup.published_at'))->not->toBeNull()
        ->and(Storage::disk(GuidedSetupLogo::DISK)->exists('guided-setup/logo.png'))->toBeTrue()
        ->and(AttachGuidedSetupLogoAction::pending($site))->toBeTrue();

    config()->set('media-library.disk_name', 'public');
    AttachGuidedSetupLogoAction::run($site->refresh());

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(1)
        ->and(AttachGuidedSetupLogoAction::pending($site))->toBeFalse()
        ->and(Storage::disk(GuidedSetupLogo::DISK)->exists('guided-setup/logo.png'))->toBeFalse();
});

/** A person's save of a page in the editor, which records their revision. */
function editAsPerson(Page $page, string $content): void
{
    test()->actingAs(guidedSetupAdmin());
    $page->translations()->firstOrFail()->update(['content' => $content]);
    PageSavedAction::run($page->refresh());
}

/** A person publishing, scheduling, unpublishing or reverting a page from the editor. */
function transitionAsPerson(Page $page, PublicationTransition $transition, ?CarbonImmutable $at = null): void
{
    $admin = guidedSetupAdmin();
    test()->actingAs($admin);
    TransitionPublicationAction::run(new PublicationTransitionRequestData($page->refresh(), $transition, $admin, CarbonImmutable::now(), $at));
}

/** A one-pixel PNG held back by setup, as an upload leaves it. */
function holdSetupLogo(): string
{
    Storage::disk(GuidedSetupLogo::DISK)->put('guided-setup/logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

    return 'guided-setup/logo.png';
}

it('keeps Publish on offer for the untouched pages after the person edits the home page, and leaves their home page as they wrote it', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about'), $site);
    $home = $site->pages()->homePage()->firstOrFail();
    editAsPerson($home, '<p>Our own words</p>');

    expect(ResolveGuidedSetupStateAction::make()->awaitingPublication(guidedSetupAdmin())?->is($site))->toBeTrue();

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect((string) $home->refresh()->translations()->firstOrFail()->content)->toBe('<p>Our own words</p>')
        ->and(createdPage($site, 'about')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeTrue();
});

it('keeps Publish on offer for the rest after a page is published and unpublished from the editor, and never republishes it', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about', 'contact'), $site);
    $about = createdPage($site, 'about');
    transitionAsPerson($about, PublicationTransition::PublishNow);
    transitionAsPerson($about, PublicationTransition::Unpublish);

    expect(ResolveGuidedSetupStateAction::make()->awaitingPublication(guidedSetupAdmin())?->is($site))->toBeTrue()
        ->and(data_get($site->refresh()->meta, 'guided_setup.published_at'))->toBeNull();

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect($about->refresh()->publishVisibilityState())->not->toBe(PublishVisibilityStateEnum::published)
        ->and(createdPage($site, 'contact')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('never republishes a page the person published and put back to draft', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about', 'contact'), $site);
    $about = createdPage($site, 'about');
    transitionAsPerson($about, PublicationTransition::PublishNow);
    transitionAsPerson($about, PublicationTransition::RevertToDraft);

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect($about->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft)
        ->and(createdPage($site, 'contact')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});

it('attaches nothing early when a page is scheduled from the editor, and leaves it scheduled on Publish', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about', 'contact'), $site, holdSetupLogo());
    $about = createdPage($site, 'about');
    transitionAsPerson($about, PublicationTransition::SchedulePublish, CarbonImmutable::now()->addWeek());
    $scheduled = $about->refresh()->visible_from;

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(0)
        ->and(data_get($site->meta, 'guided_setup.published_at'))->toBeNull();

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect($about->refresh()->visible_from?->toDateString())->toBe($scheduled?->toDateString())
        ->and($about->publishVisibilityState())->not->toBe(PublishVisibilityStateEnum::published)
        ->and($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(1);
});

it('counts a property value written on the home page as a person edit, so Publish leaves the home page as it is', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about'), $site);
    $home = $site->pages()->homePage()->firstOrFail();
    $before = (string) $home->translations()->firstOrFail()->getRawOriginal('content');
    $set = PropertySet::factory()->create(['key' => 'test.home']);
    PropertyDefinition::factory()->create(['property_set_id' => $set->id, 'key' => 'headline', 'type' => PropertyType::Text]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $home->blueprint_id, 'property_set_id' => $set->id]);
    test()->actingAs(guidedSetupAdmin());

    SetPagePropertyValuesAction::run($home->refresh(), [new PropertyValueData(propertyKey: 'headline', type: PropertyType::Text, value: 'Our headline')]);

    expect(ResolveGuidedSetupStateAction::isPlaceholderHome($site->refresh(), $home->refresh()))->toBeFalse();

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect((string) $home->refresh()->translations()->firstOrFail()->getRawOriginal('content'))->toBe($before);
});

it('stops offering Publish, without recording a publish, once nothing setup created is untouched', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about'), $site);
    editAsPerson($site->pages()->homePage()->firstOrFail(), '<p>Our own words</p>');
    transitionAsPerson(createdPage($site, 'about'), PublicationTransition::PublishNow);

    expect(ResolveGuidedSetupStateAction::make()->awaitingPublication(guidedSetupAdmin()))->toBeNull()
        ->and(data_get($site->refresh()->meta, 'guided_setup.published_at'))->toBeNull()
        ->and(fn (): int => PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin()))->toThrow(GuidedSetupRefusedException::class);
});

it('settles a site set up before setup kept its spec, once, and never offers its Publish', function (): void {
    $site = installedSite();
    CompleteGuidedSetupAction::run(guidedInput('about'), $site);
    $meta = $site->refresh()->meta;
    Arr::forget($meta, ['guided_setup.spec', 'guided_setup.page_uuids']);
    $site->forceFill(['meta' => $meta])->save();
    $admin = guidedSetupAdmin();

    expect(ResolveGuidedSetupStateAction::make()->awaitingPublication($admin))->toBeNull()
        ->and(ResolveGuidedSetupStateAction::make()->strandedSetup($admin)?->is($site))->toBeTrue()
        ->and(SettleStrandedGuidedSetupAction::run($site))->toBeTrue()
        ->and(SettleStrandedGuidedSetupAction::run($site->refresh()))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::make()->strandedSetup($admin))->toBeNull()
        ->and(fn (): int => PublishGuidedSetupSiteAction::run($site->refresh(), $admin))->toThrow(GuidedSetupRefusedException::class);
});

it('attaches the held logo once when a second request read the site before the first attached it', function (): void {
    $site = installedSite();
    CompleteGuidedSetupAction::run(guidedInput('about'), $site, holdSetupLogo());
    $meta = $site->refresh()->meta;
    data_set($meta, 'guided_setup.published_at', now()->toIso8601String());
    $site->forceFill(['meta' => $meta])->save();
    $stale = Site::query()->findOrFail($site->getKey());

    AttachGuidedSetupLogoAction::run($site);
    $attached = $site->refresh()->getFirstMedia(MediaCollectionEnum::Logo->value)?->getKey();
    holdSetupLogo();
    AttachGuidedSetupLogoAction::run($stale);

    expect($attached)->not->toBeNull()
        ->and($site->refresh()->getFirstMedia(MediaCollectionEnum::Logo->value)?->getKey())->toBe($attached);
});

it('leaves a created page that a package scheduled, with no person involved, as it is on Publish', function (): void {
    $site = installedSite();
    allowPublishing();
    CompleteGuidedSetupAction::run(guidedInput('about', 'contact'), $site);
    $about = createdPage($site, 'about');
    $about->forceFill(['visible_from' => CarbonImmutable::now()->addWeek()])->save();
    $scheduled = $about->refresh()->visible_from?->toDateString();

    PublishGuidedSetupSiteAction::run($site->refresh(), guidedSetupAdmin());

    expect($about->refresh()->visible_from?->toDateString())->toBe($scheduled)
        ->and(createdPage($site, 'contact')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
});
