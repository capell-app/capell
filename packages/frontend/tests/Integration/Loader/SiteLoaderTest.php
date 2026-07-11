<?php

declare(strict_types=1);

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Loader\SiteLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

it('loads languages in order', function (): void {
    $french = Language::factory()->french()->create();
    $english = Language::factory()->english()->create();
    $german = Language::factory()->german()->create();

    $site = Site::factory()->language($english)->create();
    SiteDomain::factory()
        ->site($site)
        ->forEachSequence(
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $french->id, 'path' => '/fr'],
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $english->id, 'path' => null],
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $german->id, 'path' => '/de'],
        )
        ->create();

    $siteLanguages = SiteLoader::languages();
    $firstLanguage = $siteLanguages[0] ?? null;
    $secondLanguage = $siteLanguages[1] ?? null;
    $thirdLanguage = $siteLanguages[2] ?? null;

    assert($firstLanguage instanceof Language);
    assert($secondLanguage instanceof Language);
    assert($thirdLanguage instanceof Language);

    expect($siteLanguages)
        ->toHaveCount(3)
        ->and($firstLanguage->code)->toEqual($english->code)
        ->and($secondLanguage->code)->toEqual($french->code)
        ->and($thirdLanguage->code)->toEqual($german->code);
});

it('returns a real Collection on back-to-back cache-hit reads', function (): void {
    // Regression guard for the `__PHP_Incomplete_Class` TypeError that fired
    // on every second request when the Language class wasn't primed before
    // the cache read. See the top of SiteLoader::languages().
    $english = Language::factory()->english()->create();
    $site = Site::factory()->language($english)->create();
    SiteDomain::factory()
        ->site($site)
        ->create(['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $english->id, 'path' => null]);

    // Populate the cache.
    SiteLoader::languages();

    // Simulate a fresh request: clear the per-request in-memory cache on the
    // CapellCore manager so the next call deserializes from the backing store.
    $manager = CapellCore::getFacadeRoot();
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('localCache');
    $property->setValue($manager, []);

    // This is the call that used to crash with TypeError.
    $languages = SiteLoader::languages();

    expect($languages)
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(1)
        ->and($languages->first())->toBeInstanceOf(Language::class);
});

it('loads site languages', function (): void {
    $french = Language::factory()->french()->create();
    $english = Language::factory()->english()->create();
    $german = Language::factory()->german()->create();
    $site = Site::factory()->language($english)->create();
    SiteDomain::factory()
        ->site($site)
        ->forEachSequence(
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $french->id, 'path' => '/fr'],
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $english->id, 'path' => null],
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $german->id, 'path' => '/de'],
        )
        ->create();

    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();

    $page = Page::factory()->site($site)->withTranslations(languages: collect([$english, $german]), slug: 'test')->create();
    $page->load('pageUrls.siteDomain');

    $siteLanguages = SiteLoader::pageLanguages($site, $english, $page);

    expect($siteLanguages)
        ->toHaveCount(3)
        ->and($siteLanguages[0])->toMatchArray([
            'code' => $english->code,
            'name' => $english->name,
            'url' => 'http://localhost/test',
        ])
        ->and($siteLanguages[1])->toMatchArray([
            'code' => $french->code,
            'name' => $french->name,
            'url' => 'http://localhost/fr',
        ])
        ->and($siteLanguages[2])->toMatchArray([
            'code' => $german->code,
            'name' => $german->name,
            'url' => 'http://localhost/de/test',
        ]);
});

it('returns the current page url directly when a site has one language', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->language($english)->withTranslations($english)->create();
    $page = Page::factory()
        ->site($site)
        ->published()
        ->withTranslations($english, ['title' => 'Only language'], slug: '/only-language')
        ->create();
    $page->load('pageUrl.siteDomain', 'pageUrls.siteDomain', 'blueprint');

    $siteLanguages = SiteLoader::pageLanguages($site, $english, $page);

    expect($siteLanguages)->toHaveCount(1)
        ->and($siteLanguages[0])->toMatchArray([
            'id' => $english->id,
            'code' => $english->code,
            'name' => $english->name,
            'url' => $page->pageUrl->full_url,
        ]);
});

it('getSites sets language relations on siteDomains and translations', function (): void {
    $french = Language::factory()->french()->create();
    $english = Language::factory()->english()->create();
    $site = Site::factory()->language($english)->create();
    SiteDomain::factory()
        ->site($site)
        ->forEachSequence(
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $french->id, 'path' => '/fr'],
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $english->id, 'path' => null],
        )
        ->create();

    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();

    $sites = SiteLoader::getSites();

    // Find our created site in the collection
    $loadedSite = $sites->firstWhere('id', $site->id);
    expect($loadedSite)->not()->toBeNull();
    assert($loadedSite instanceof Site);

    $firstDomain = $loadedSite->siteDomains[0] ?? null;
    $secondDomain = $loadedSite->siteDomains[1] ?? null;
    assert($firstDomain instanceof SiteDomain);
    assert($secondDomain instanceof SiteDomain);

    expect($loadedSite->siteDomains)->toHaveCount(2);
    expect($firstDomain->getRelation('language'))->toBeInstanceOf(Language::class)
        ->and($firstDomain->getRelation('language')->id)->toBe($french->id);
    expect($secondDomain->getRelation('language'))->toBeInstanceOf(Language::class)
        ->and($secondDomain->getRelation('language')->id)->toBe($english->id);

    $translations = $loadedSite->translations;
    $translations->each(function ($translation): void {
        expect($translation->getRelation('language'))->toBeInstanceOf(Language::class);
    });
});

it('loads related sites for the current language', function (): void {
    $english = Language::query()->create([
        'name' => 'English',
        'locale' => 'en',
        'code' => 'en',
        'flag' => 'gb-eng',
        'status' => true,
        'default' => true,
        'order' => 1,
    ]);
    $french = Language::query()->create([
        'name' => 'Français',
        'locale' => 'fr',
        'code' => 'fr',
        'flag' => 'fr',
        'status' => true,
        'default' => false,
        'order' => 2,
    ]);

    $site = Site::factory()->language($english)->withTranslations($english)->create();
    $englishRelated = Site::factory()->language($english)->withTranslations($english)->create();
    $frenchRelated = Site::factory()->language($french)->withTranslations($french)->create();

    $site->update([
        'meta' => [
            'related' => [
                $englishRelated->id,
                $frenchRelated->id,
            ],
        ],
    ]);

    $relatedSites = SiteLoader::related($site->fresh(), $english);

    expect($relatedSites)
        ->toHaveCount(1)
        ->and($relatedSites->first())->toBeInstanceOf(Site::class)
        ->and($relatedSites->first()?->is($englishRelated))->toBeTrue()
        ->and($relatedSites->first()?->relationLoaded('siteDomain'))->toBeTrue()
        ->and($relatedSites->first()?->relationLoaded('translation'))->toBeTrue();
});

it('tracks site, domain, and translation models when getSites runs from cache', function (): void {
    $french = Language::factory()->french()->create();
    $english = Language::factory()->english()->create();
    $site = Site::factory()->language($english)->create();
    SiteDomain::factory()
        ->site($site)
        ->forEachSequence(
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $french->id, 'path' => '/fr'],
            ['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $english->id, 'path' => null],
        )
        ->create();

    Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();

    // Prime the cache by calling getSites once
    SiteLoader::getSites();

    // Clear the in-memory cache to force cache hit on next call
    $manager = CapellCore::getFacadeRoot();
    $managerReflection = new ReflectionClass($manager);
    $cacheProperty = $managerReflection->getProperty('localCache');
    $cacheProperty->setValue($manager, []);

    $store = new class implements RenderedModelTracker
    {
        /** @var array<string, int> */
        private array $tracked = [];

        public function track(Model $model): void
        {
            $this->tracked[class_basename($model)] = $this->tracked(class_basename($model)) + 1;
        }

        public function trackByClass(Model $model, string $modelClass): void
        {
            $this->tracked[class_basename($modelClass)] = $this->tracked(class_basename($modelClass)) + 1;
        }

        public function tracked(string $modelType): int
        {
            return $this->tracked[$modelType] ?? 0;
        }
    };
    app()->instance(RenderedModelTracker::class, $store);

    // This call hits the cache and should track models
    SiteLoader::getSites();

    expect($store->tracked('Site'))->toBeGreaterThan(0)
        ->and($store->tracked('SiteDomain'))->toBeGreaterThan(0)
        ->and($store->tracked('Translation'))->toBeGreaterThan(0);
});

it('ignores null loaded model relations when loadSite runs from cache', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->language($english)->create();
    SiteDomain::factory()
        ->site($site)
        ->create(['scheme' => 'http', 'domain' => 'localhost', 'language_id' => $english->id, 'path' => null]);

    $cachedSite = $site->fresh();
    expect($cachedSite)->toBeInstanceOf(Site::class);

    $cachedSite->setRelation('type', null);
    CapellCore::setToCache(CacheEnum::site($site->id, $english->id), $cachedSite);

    $manager = CapellCore::getFacadeRoot();
    $managerReflection = new ReflectionClass($manager);
    $cacheProperty = $managerReflection->getProperty('localCache');
    $cacheProperty->setValue($manager, []);

    $store = new class implements RenderedModelTracker
    {
        /** @var list<string> */
        public array $tracked = [];

        public function track(Model $model): void
        {
            $this->tracked[] = class_basename($model);
        }

        public function trackByClass(Model $model, string $modelClass): void
        {
            $this->tracked[] = class_basename($modelClass);
        }

        public function tracked(string $modelType): int
        {
            return count(array_filter(
                $this->tracked,
                static fn (string $trackedModel): bool => $trackedModel === $modelType,
            ));
        }
    };
    app()->instance(RenderedModelTracker::class, $store);

    $loadedSite = SiteLoader::loadSite($site, $english);

    expect($loadedSite)->toBeInstanceOf(Site::class)
        ->and($store->tracked('Site'))->toBe(1);
});

it('tracks cached site media theme media and loaded relations while preserving derived media slots', function (): void {
    $english = Language::factory()->english()->create();
    $theme = Theme::factory()->create();
    $site = Site::factory()
        ->language($english)
        ->theme($theme)
        ->withTranslations($english)
        ->create();
    $image = Media::factory()->model($site)->collection(MediaCollectionEnum::Image)->create();
    $logo = Media::factory()->model($site)->collection(MediaCollectionEnum::Logo)->create();
    $themeMedia = Media::factory()->model($theme)->collection(MediaCollectionEnum::Image)->create();

    $cachedSite = $site->fresh(['language', 'media', 'theme.media', 'blueprint', 'translations', 'siteDomains']);
    expect($cachedSite)->toBeInstanceOf(Site::class);

    CapellCore::setToCache(CacheEnum::site($site->id, $english->id), $cachedSite);

    $manager = CapellCore::getFacadeRoot();
    $managerReflection = new ReflectionClass($manager);
    $cacheProperty = $managerReflection->getProperty('localCache');
    $cacheProperty->setValue($manager, []);

    $store = new class implements RenderedModelTracker
    {
        /** @var array<string, int> */
        private array $tracked = [];

        public function track(Model $model): void
        {
            $this->tracked[class_basename($model)] = $this->tracked($model::class) + 1;
        }

        public function trackByClass(Model $model, string $modelClass): void
        {
            $this->tracked[class_basename($modelClass)] = $this->tracked($modelClass) + 1;
        }

        public function tracked(string $modelType): int
        {
            return $this->tracked[class_basename($modelType)] ?? 0;
        }
    };
    app()->instance(RenderedModelTracker::class, $store);

    $loadedSite = expectPresent(SiteLoader::loadSite($site, $english));

    expect($loadedSite->image?->is($image))->toBeTrue()
        ->and($loadedSite->logo?->is($logo))->toBeTrue()
        ->and($loadedSite->theme?->media->contains('id', $themeMedia->id))->toBeTrue()
        ->and($store->tracked(Site::class))->toBeGreaterThanOrEqual(1)
        ->and($store->tracked(Media::class))->toBeGreaterThanOrEqual(3)
        ->and($store->tracked(Theme::class))->toBeGreaterThanOrEqual(1);
});
