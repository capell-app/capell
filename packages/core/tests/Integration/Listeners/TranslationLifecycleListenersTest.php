<?php

declare(strict_types=1);

use Capell\Core\Actions\UpdatePageUrlAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Events\PageUrlsRewritten;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

it('runs update page url action when a page translation is updated', function (): void {
    UpdatePageUrlAction::shouldRun()->once();

    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->create();
    $translation = Translation::factory()->recycle($language)->translatable($page)->state(['title' => $page->name])->create();

    expect($translation)->slug->toBe(str($page->name)->slug()->toString());
});

it('runs creating, created, and updated listeners for page translations', function (): void {
    Event::fake([PageUrlsRewritten::class]);

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

    Event::assertDispatched(
        PageUrlsRewritten::class,
        fn (PageUrlsRewritten $event): bool => $event->page->is($page)
            && $event->urlChanges === [
                $language->getKey() => [
                    'old' => '/welcome-home',
                    'new' => '/welcome-updated',
                ],
            ]
            && $event->descendantUrlChanges === [],
    );
});

it('rewrites descendant urls when a page translation slug changes', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $parent = Page::factory()->createOne(['site_id' => $site->id]);
    $parentTranslation = Translation::factory()
        ->translatable($parent)
        ->language($language)
        ->slug('parent')
        ->create();
    $child = Page::factory()->createOne([
        'site_id' => $site->id,
        'parent_id' => $parent->id,
    ]);
    Translation::factory()
        ->translatable($child)
        ->language($language)
        ->slug('child')
        ->create();

    Event::fake([PageUrlsRewritten::class]);

    $parentTranslation->meta = [
        'slug' => 'renamed-parent',
    ];
    $parentTranslation->save();

    expect($child->pageUrl->fresh())->url->toBe('/renamed-parent/child');
    Event::assertDispatched(
        PageUrlsRewritten::class,
        fn (PageUrlsRewritten $event): bool => $event->page->is($parent)
            && $event->descendantUrlChanges === [
                $child->getKey() => [
                    $language->getKey() => [
                        'old' => '/parent/child',
                        'new' => '/renamed-parent/child',
                    ],
                ],
            ],
    );
});

it('creates page translation metadata without lazy loading its pageable', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne([
        'name' => 'Strict Loading Page',
    ]);

    $firstTranslation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->create();

    $secondTranslation = Translation::factory()
        ->translatable(Page::factory()->createOne())
        ->create();

    Model::preventLazyLoading();

    try {
        $translations = Translation::query()
            ->whereKey([$firstTranslation->getKey(), $secondTranslation->getKey()])
            ->get();

        $translation = $translations->firstOrFail(
            fn (Translation $candidate): bool => $candidate->is($firstTranslation),
        );

        $translation->forceFill([
            'title' => null,
            'meta' => [],
        ]);

        Event::dispatch('eloquent.creating: ' . Translation::class, [$translation]);

        expect($translation->relationLoaded('translatable'))->toBeTrue();
        expect($translation->translatable)->toBeInstanceOf(Page::class);
        expect($translation->content)->toBeString();

        expect($translation)
            ->title->toBe('Strict Loading Page')
            ->slug->toBe('strict-loading-page');
    } finally {
        Model::preventLazyLoading(false);
    }
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

it('rebuilds content graph edges after a page translation is saved', function (): void {
    $embeddedPage = Page::factory()->createOne();
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $embeddingPage = Page::factory()->type($blueprint)->createOne();

    Translation::factory()
        ->translatable($embeddingPage)
        ->createOne([
            'content' => [
                [
                    'type' => 'feature',
                    'data' => ['page_id' => $embeddedPage->getKey()],
                ],
            ],
        ]);

    $edgeExists = fn (): bool => ContentGraphEdge::query()
        ->where('source_type', Page::class)
        ->where('source_id', $embeddingPage->getKey())
        ->where('target_type', Page::class)
        ->where('target_id', $embeddedPage->getKey())
        ->where('kind', ContentGraphEdgeKind::FoundOnPage)
        ->exists();

    expect($edgeExists())->toBeFalse();

    $deferredCallbacks = defer();

    expect($deferredCallbacks)->not->toBeEmpty();

    $deferredCallbacks->invoke();

    expect($edgeExists())->toBeTrue();
});

it('rebuilds content graph edges after a real page rollback', function (): void {
    $embeddedPage = Page::factory()->createOne();
    $otherEmbeddedPage = Page::factory()->createOne();
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $embeddingPage = Page::factory()->type($blueprint)->createOne();

    $translation = Translation::factory()
        ->translatable($embeddingPage)
        ->createOne([
            'content' => [['type' => 'feature', 'data' => ['page_id' => $embeddedPage->getKey()]]],
        ]);
    $embeddingPage->load('translations');
    $embeddingPage->save();

    $targetVersion = resolve(RollbackService::class)->currentVersion($embeddingPage->uuid);

    $translation->update([
        'content' => [['type' => 'feature', 'data' => ['page_id' => $otherEmbeddedPage->getKey()]]],
    ]);
    defer()->invoke();

    expect(ContentGraphEdge::query()->where('source_id', $embeddingPage->getKey())->where('target_id', $otherEmbeddedPage->getKey())->exists())->toBeTrue();

    ApplyRollbackAction::run($embeddingPage->fresh(), $targetVersion);

    expect(ContentGraphEdge::query()
        ->where('source_id', $embeddingPage->getKey())
        ->where('target_id', $embeddedPage->getKey())
        ->where('kind', ContentGraphEdgeKind::FoundOnPage)
        ->exists())->toBeTrue()
        ->and(ContentGraphEdge::query()
            ->where('source_id', $embeddingPage->getKey())
            ->where('target_id', $otherEmbeddedPage->getKey())
            ->where('kind', ContentGraphEdgeKind::FoundOnPage)
            ->exists())->toBeFalse();
});
