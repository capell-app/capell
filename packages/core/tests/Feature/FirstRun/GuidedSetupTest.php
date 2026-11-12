<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\FirstRun\BuildGuidedSetupSpecAction;
use Capell\Core\Actions\FirstRun\CompleteGuidedSetupAction;
use Capell\Core\Actions\FirstRun\RecordInstallSuiteAction;
use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Actions\FirstRun\SkipGuidedSetupAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Core\Support\FirstRun\InstallerPages;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecPresetRegistry;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

/** A freshly installed site, given its default pages by the installer's own action: nothing set up yet. */
function installedSite(): Site
{
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey(), 'name' => 'My Laravel App']);
    CreateDefaultPagesAction::run($site->refresh(), $site->languages);

    return $site->refresh();
}

/** The installer's pages beside the home page: not found, maintenance and welcome. */
const INSTALLER_EXTRA_PAGES = 3;

/** @return array<int, Page> the site's pages that guided setup wrote: its home page and the pages it added */
function guidedSetupPages(Site $site): array
{
    return Page::query()->where('site_id', $site->getKey())->with('translations')->get()
        ->reject(fn (Page $page): bool => in_array($page->translations->first()?->meta['slug'] ?? null, ['not-found', 'maintenance', 'welcome'], true))
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
        ->and(data_get($site->meta, 'guided_setup.preset_key'))->toBe('service-business')
        ->and(data_get($site->meta, 'guided_setup.completed_at'))->not->toBeNull()
        ->and(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse();
});

it('attaches the uploaded logo to the site', function (): void {
    $site = installedSite();
    $logo = tempnam(sys_get_temp_dir(), 'logo') . '.png';
    file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true));

    try {
        CompleteGuidedSetupAction::run(guidedInput(), $site, $logo);
    } finally {
        unlink($logo);
    }

    expect($site->refresh()->getMedia(MediaCollectionEnum::Logo->value))->toHaveCount(1);
});

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
        ->and(data_get($site->meta, 'guided_setup'))->toBeNull();
});

it('stops asking once setup is skipped', function (): void {
    $site = installedSite();
    $user = guidedSetupAdmin();

    SkipGuidedSetupAction::run($site);

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse()
        ->and($site->pages()->count())->toBe(1 + INSTALLER_EXTRA_PAGES);
});

it('still asks while the only public home page is the one the installer published', function (): void {
    $site = installedSite();
    $home = $site->pages()->homePage()->firstOrFail();

    expect($home->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeTrue();
});

it('never asks about a site whose home page went public outside the installer', function (): void {
    $site = installedSite();
    // An install from before the installer recorded its pages: its public home page may be a launched site.
    $site->forceFill(['meta' => Arr::except($site->meta ?? [], InstallerPages::META_KEY)])->save();

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

    // Publishing the home page records the go-live, as the publication listener does.
    RecordSitePublishedAction::run((int) $site->getKey());

    expect($state->awaitingPublication($actor))->toBeNull();
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

it('does not owe setup to an unlaunched site that already holds another page, and refuses it if forced', function (): void {
    $site = installedSite();
    $about = Page::factory()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    $user = guidedSetupAdmin();

    expect(ResolveGuidedSetupStateAction::run($user)->needsSetup)->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeTrue();

    expect(fn (): mixed => CompleteGuidedSetupAction::run(guidedSetupInput(), $site))
        ->toThrow(GuidedSetupRefusedException::class, 'already has pages or edits')
        ->and($site->pages()->count())->toBe(2 + INSTALLER_EXTRA_PAGES)
        ->and($about->refresh()->exists)->toBeTrue();
});

it("owes setup to a fresh install whose only pages are the installer's own, and records them", function (): void {
    $site = installedSite();

    expect($site->pages()->count())->toBe(1 + INSTALLER_EXTRA_PAGES)
        ->and(data_get($site->meta, InstallerPages::META_KEY))->toHaveCount(1 + INSTALLER_EXTRA_PAGES)
        ->and(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeTrue();
});

it("counts a page added by an extension's default page as the installer's own", function (): void {
    CapellCore::addDefaultPage('team', 'Team', function (Site $site, Collection $languages): void {
        resolve(PageCreator::class)->createPage(['name' => 'Team', 'translation' => ['slug' => 'team'], 'visible_from' => PublishSentinel::draftValue()], $site, $languages);
    });
    config()->set('capell.default_pages', ['home', 'error_404', 'maintenance', 'welcome', 'team']);

    $site = installedSite();

    expect($site->pages()->count())->toBe(2 + INSTALLER_EXTRA_PAGES)
        ->and(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeFalse();
});

it('owes setup to an install from before the installer recorded its pages while they are untouched and private', function (): void {
    $site = installedSite();
    $site->forceFill(['meta' => Arr::except($site->meta ?? [], InstallerPages::META_KEY)])->save();
    $site->pages()->homePage()->firstOrFail()->update(['visible_from' => PublishSentinel::draftValue()]);

    expect(ResolveGuidedSetupStateAction::hasExistingWork($site->refresh()))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeTrue();
});

it("does not owe setup once a person has edited one of the installer's other pages", function (): void {
    $site = installedSite();
    $notFound = $site->pages()->notHomePage()->firstOrFail();
    PageRevision::query()->create([
        'page_uuid' => $notFound->uuid,
        'version' => 1,
        'actor_id' => guidedSetupAdmin()->getKey(),
        'summary' => 'Edited',
        'is_rollback' => false,
        'source' => 'edit',
        'occurred_at' => now(),
    ]);

    expect(ResolveGuidedSetupStateAction::hasExistingWork($site))->toBeTrue()
        ->and(ResolveGuidedSetupStateAction::run(guidedSetupAdmin())->needsSetup)->toBeFalse();
});

it("drafts the installer's public home page before writing over it, so nothing goes live until the person publishes", function (): void {
    $site = installedSite();

    CompleteGuidedSetupAction::run(guidedInput('about'), $site);

    expect($site->pages()->homePage()->firstOrFail()->publishVisibilityState())->not->toBe(PublishVisibilityStateEnum::published)
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
