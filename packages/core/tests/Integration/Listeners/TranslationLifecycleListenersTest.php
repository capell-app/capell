<?php

declare(strict_types=1);

use Capell\Core\Actions\UpdatePageUrlAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Facades\Cache;

it('runs update page url action when a page translation is updated', function (): void {
    UpdatePageUrlAction::shouldRun()->once();

    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->create();
    $translation = Translation::factory()->recycle($language)->translatable($page)->state(['title' => $page->name])->create();

    expect($translation)->slug->toBe(str($page->name)->slug()->toString());
});

it('runs creating, created, and updated listeners for page translations', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne([
        'site_id' => $site->id,
        'name' => 'Welcome Home',
    ]);

    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->state([
            'title' => null,
            'meta' => [],
        ])
        ->create();

    $page->refresh();

    expect($page)
        ->name->toBe('Welcome Home')
        ->translations->toHaveCount(1)
        ->translation->title->toBe('Welcome Home')
        ->pageUrls->toHaveCount(1)
        ->and($page->pageUrl)
        ->url->toBe('/welcome-home')
        ->language_id->toBe($language->id)
        ->site_id->toBe($site->id);

    $translation->meta = [
        'slug' => 'welcome-updated',
    ];
    $translation->save();

    expect($page->pageUrl->fresh())->url->toBe('/welcome-updated');
});

it('runs saved and deleted listeners for pageable translations', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne([
        'site_id' => $site->id,
    ]);

    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->create();

    $cacheDriver = Cache::driver('array');
    $registryKey = 'capell-core-cache-keys';

    CapellCoreHelper::getSiteLanguagesForRecord($translation, $site->id);
    CapellCoreHelper::relationExists($page, 'translations');

    $firstPageCacheKey = CacheEnum::FirstPageByTypeForSite->value . '-translation-listener-test';
    $cacheDriver->forever($firstPageCacheKey, true);

    $registry = $cacheDriver->get($registryKey, []);
    $cacheDriver->forever($registryKey, array_values(array_unique([
        ...$registry,
        $firstPageCacheKey,
    ])));

    $translation->title = 'Updated title';
    $translation->save();

    $registryAfterSave = $cacheDriver->get($registryKey, []);

    $hasFlushedPrefixesAfterSave = collect($registryAfterSave)->contains(
        fn (string $cacheKey): bool => str_starts_with($cacheKey, CacheEnum::SiteLanguages->value)
                || str_starts_with($cacheKey, CacheEnum::RelationExists->value)
                || str_starts_with($cacheKey, CacheEnum::FirstPageByTypeForSite->value),
    );

    expect($hasFlushedPrefixesAfterSave)->toBeFalse();

    CapellCoreHelper::getSiteLanguagesForRecord($translation, $site->id);
    CapellCoreHelper::relationExists($page, 'translations');

    $registryBeforeDelete = $cacheDriver->get($registryKey, []);
    $cacheDriver->forever($registryKey, array_values(array_unique([
        ...$registryBeforeDelete,
        $firstPageCacheKey,
    ])));

    $cacheDriver->forever($firstPageCacheKey, true);

    $translation->delete();

    $registryAfterDelete = $cacheDriver->get($registryKey, []);

    $hasFlushedPrefixesAfterDelete = collect($registryAfterDelete)->contains(
        fn (string $cacheKey): bool => str_starts_with($cacheKey, CacheEnum::SiteLanguages->value)
                || str_starts_with($cacheKey, CacheEnum::RelationExists->value)
                || str_starts_with($cacheKey, CacheEnum::FirstPageByTypeForSite->value),
    );

    expect($hasFlushedPrefixesAfterDelete)->toBeFalse();
});
