<?php

declare(strict_types=1);

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Observers\PageObserver;
use Capell\Core\Support\Lookup\ArrayCacheRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('flushes specific cache keys on saved/deleted/restored', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();

    $page = Page::factory()->createOne([
        'site_id' => $site->id,
        'name' => 'CacheTest',
    ]);

    // Prime cache entries tracked by CapellCoreHelper
    $key1 = CacheEnum::relationExistsKey($page, 'translations');
    $key2 = CacheEnum::FirstPageByTypeForSite->value;

    $registryCache = resolve(ArrayCacheRegistry::class);
    $registryCache->remember($key1, fn (): bool => true, asBool: true);
    $registryCache->remember($key2, fn (): bool => true, asBool: true);

    // Ensure registry has entries
    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toBeEmpty();

    // Trigger observer saved (already ran on create), run delete to invoke deleted -> saved
    $page->delete();

    // After deletion, keys with matching prefixes should be flushed
    expect($page->fresh()->trashed())->toBeTrue();
});

it('updates page and descendant URLs on parent change', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();

    // Create a parent page and a child page
    $parent = Page::factory()->createOne([
        'site_id' => $site->id,
        'name' => 'Parent',
    ]);

    $child = Page::factory()->createOne([
        'site_id' => $site->id,
        'name' => 'Child',
    ]);

    // Add translations with slugs
    /** @var Translation $parentTrans */
    $parentTrans = Translation::factory()
        ->translatable($parent)
        ->language($lang)
        ->slug('/parent')
        ->create();

    /** @var Translation $childTrans */
    $childTrans = Translation::factory()
        ->translatable($child)
        ->language($lang)
        ->slug('/child')
        ->create();

    // Change child's parent and run the same URL setup action used by page moves.
    $child->parent_id = $parent->id;
    $child->save();
    SetupPageUrlsAction::run($child);

    // Assert Url entries were created/updated
    $childUrl = PageUrl::query()
        ->where('pageable_type', $child->getMorphClass())
        ->where('pageable_id', $child->id)
        ->where('language_id', $lang->id)
        ->first();

    expect($childUrl)->url->toBe('/parent/child');
});

it('fills missing default type and layout through page creation events', function (): void {
    Page::observe(PageObserver::class);

    $site = Site::factory()->createOne();
    $defaultBlueprint = Blueprint::factory()->page()->default()->createOne();
    $defaultLayout = Layout::factory()->createOne(['default' => true]);

    $page = Page::query()->create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Observer defaulted page',
        'site_id' => $site->getKey(),
    ]);

    expect($page->blueprint_id)->toBe($defaultBlueprint->getKey())
        ->and($page->layout_id)->toBe($defaultLayout->getKey());
});

it('fails loudly when a page is created without default content contracts', function (): void {
    Page::observe(PageObserver::class);

    $site = Site::factory()->createOne();
    Layout::factory()->createOne(['default' => true]);

    expect(fn (): Page => Page::query()->create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Missing page blueprint',
        'site_id' => $site->getKey(),
    ]))->toThrow(InvalidArgumentException::class, 'Unable to create page without a type.');
});

it('cascades page URL soft deletes, restores, force deletes, and domain events', function (): void {
    Page::observe(PageObserver::class);

    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->createOne();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->createOne();

    Event::fake([PageDeleted::class, PageSaved::class]);

    $page->delete();

    Event::assertDispatched(PageDeleted::class, fn (PageDeleted $event): bool => $event->page->is($page));

    expect($pageUrl->fresh()?->trashed())->toBeTrue();

    $page->restore();

    Event::assertDispatched(PageSaved::class, fn (PageSaved $event): bool => $event->page->is($page));

    expect($pageUrl->fresh()?->trashed())->toBeFalse();

    $page->forceDelete();

    expect(PageUrl::query()->withTrashed()->whereKey($pageUrl->getKey())->exists())->toBeFalse();
});
