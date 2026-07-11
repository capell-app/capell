<?php

declare(strict_types=1);

use Capell\Core\Database\Factories\AssetAttachmentFactory;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

it('returns true if blueprint key is error', function (): void {
    $page = new Page;
    $type = new Blueprint;
    $type->key = 'error';

    $page->setRelation('blueprint', $type);
    expect($page->isErrorPage())->toBeTrue();
});

it('returns false if blueprint key is not error', function (): void {
    $page = new Page;
    $type = new Blueprint;
    $type->key = 'normal';

    $page->setRelation('blueprint', $type);
    expect($page->isErrorPage())->toBeFalse();
});

it('creates AssetAttachment via factory and resolves HasAssets relations', function (): void {
    // Arrange: a related Page and an asset Page
    $relatedPage = Page::factory()->createOne();

    // Use the AssetAttachmentFactory to create a relation for the related page with an asset of type Page
    /** @var AssetAttachment $relation */
    $relation = AssetAttachmentFactory::new()
        ->related($relatedPage)
        ->asset(AssetEnum::Page)
        ->create();

    // Act: reload relations to ensure they are available
    $relatedPage->load('assets');

    // Assert: the HasAssets::assets relation contains the created relation
    expect($relatedPage->assets)->toHaveCount(1)
        ->and($relatedPage->assets->first())->toBeInstanceOf(AssetAttachment::class);

    // The asset side resolves to a Page via morph
    expect($relation->asset)->toBeInstanceOf(Page::class);

    // The asset Page should expose the reverse relation (assetRelations)
    /** @var Page $assetPage */
    $assetPage = $relation->asset;
    $assetPage->load('assetRelations');

    expect($assetPage->assetRelations)->toBeInstanceOf(Collection::class)
        ->and($assetPage->assetRelations)->toHaveCount(1)
        ->and(expectPresent($assetPage->assetRelations->first())->getKey())->toBe($relation->getKey());
});

it('scopeWithAssets returns pages that have assets and eager-loads asset morph', function (): void {
    // Arrange: one page with an asset relation and one without
    $withAssets = Page::factory()->createOne();
    $withoutAssets = Page::factory()->createOne();

    AssetAttachmentFactory::new()
        ->related($withAssets)
        ->asset(AssetEnum::Page)
        ->create();

    // Act: query using the HasAssets scope
    $pages = Page::query()->withAssets()->get();

    // Assert: the page with assets is included; the one without is not required to be included
    expect($pages->pluck('id'))->toContain($withAssets->id);

    // And the asset relation is eager-loaded with morph-to asset resolved
    /** @var Page $loaded */
    $loaded = expectPresent($pages->firstWhere('id', $withAssets->id));
    $loaded->load('assets.asset');

    $firstRelation = expectPresent($loaded->assets->first());
    expect($firstRelation)->toBeInstanceOf(AssetAttachment::class)
        ->and($firstRelation->asset)->toBeInstanceOf(Page::class);
});

it('populates nested set fields on full model creation', function (): void {
    $page = Page::factory()->withTranslations()->create()->refresh();

    expect($page)
        ->_lft->toBeInt()->toBeGreaterThan(0)
        ->and($page)->_rgt->toBeInt()->toBeGreaterThan($page->_lft);
});

it('assigns nested set boundaries for a child inserted under a parent', function (): void {
    $parent = Page::factory()->withTranslations()->create();
    $child = Page::factory()->site($parent->site)->parent($parent)->withTranslations()->create();

    $parent->refresh();
    $child->refresh();

    expect($parent)
        ->_lft->toBeInt()->toBeGreaterThan(0)
        ->and($parent)->_rgt->toBeInt()->toBeGreaterThan($parent->_lft);

    expect($child)
        ->parent_id->toBe($parent->id)
        ->_lft->toBeInt()->toBeGreaterThan(0)
        ->_rgt->toBeInt()->toBeGreaterThan($child->_lft)
        ->_lft->toBeGreaterThan($parent->_lft)
        ->_rgt->toBeLessThan($parent->_rgt);
});

it('can create a page with an unpublished parent', function (): void {
    $parentPage = Page::factory()->createOne();
    $childPage = Page::factory()->parent($parentPage)->create();

    expect($childPage)
        ->parent_id->toBe($parentPage->id);
});

it('resolves site and layout relations', function (): void {
    $page = Page::factory()->withTranslations()->create();

    expect($page->site)->toBeInstanceOf(Site::class)
        ->and($page->layout)->toBeInstanceOf(Layout::class);
});

it('resolves parent, children, and siblings relations', function (): void {
    $parentPage = Page::factory()->withTranslations()->create();
    $firstChild = Page::factory()->site($parentPage->site)->parent($parentPage)->withTranslations()->create();
    $secondChild = Page::factory()->site($parentPage->site)->parent($parentPage)->withTranslations()->create();

    $parentPage->load('children');
    $firstChild->load('parent');

    expect($parentPage)
        ->children->toHaveCount(2)
        ->and($firstChild->parent?->getKey())->toBe($parentPage->getKey())
        ->and($secondChild->parent?->getKey())->toBe($parentPage->getKey())
        ->and($parentPage->children->pluck('id'))->toContain($firstChild->id, $secondChild->id)
        ->and($firstChild->getSiblingsExcludingSelf())
        ->toHaveCount(1)
        ->pluck('id')->toContain($secondChild->id)
        ->pluck('id')->not()->toContain($firstChild->id);
});

it('resolves pageUrl and pageUrls relations', function (): void {
    $page = Page::factory()->withTranslations()->create();

    $extraPageUrl = PageUrl::factory()->page($page)->site($page->site)->create();

    expect($page)
        ->pageUrl->site_id->toBe($page->site_id)
        ->pageUrls->toHaveCount(2)
        ->pageUrls->pluck('id')->toContain($page->pageUrl->id, $extraPageUrl->id);
});

it('builds child page urls below the home page without duplicate leading slashes', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $homePage = Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();
    $childPage = Page::factory()
        ->site($site)
        ->parent($homePage)
        ->withTranslations(slug: 'about')
        ->create();

    expect($childPage->pageUrl->url)
        ->toBe('/about');
});

it('resolves canonicalPage, canonicalPages, and related relations', function (): void {
    $canonicalPage = Page::factory()->withTranslations()->create();
    $relatedPage = Page::factory()->withTranslations()->create();

    $page = Page::factory()->withTranslations()->create([
        'meta' => [
            'canonical_pageable_type' => $canonicalPage->getMorphClass(),
            'canonical_pageable_id' => $canonicalPage->id,
            'related' => [$relatedPage->id],
        ],
    ]);

    $page->load('canonicalPage', 'related');

    $canonicalPage->load('canonicalPages');

    expect($page->canonicalPage?->getKey())->toBe($canonicalPage->getKey())
        ->and($canonicalPage->canonicalPages->pluck('id'))->toContain($page->id)
        ->and($page->related->pluck('id'))->toContain($relatedPage->id);
});

it('resolves image relation by media collection', function (): void {
    $page = Page::factory()->withTranslations()->create();

    $media = Media::factory()->image()->model($page)->create();

    $page->load('image');

    expect($page->image?->getKey())->toBe($media->getKey());
});

// --- isAccessibleByUser ---

it('isAccessibleByUser returns true when type has no role restrictions', function (): void {
    $page = Page::factory()->createOne();
    $user = User::factory()->createOne();

    expect($page->isAccessibleByUser($user))->toBeTrue();
});

it('isAccessibleByUser returns false when type has restrictions and user lacks the role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $page = Page::factory()->createOne(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $user = User::factory()->createOne();

    expect($page->isAccessibleByUser($user))->toBeFalse();
});

it('isAccessibleByUser returns true when type is restricted and user holds matching role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $page = Page::factory()->createOne(['blueprint_id' => $type->id]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $user = User::factory()->createOne()->assignRole($role);

    expect($page->isAccessibleByUser($user))->toBeTrue();
});

it('isAccessibleByUser denies roles assigned only to another site', function (): void {
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'site-scoped-editor', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $allowedSite = Site::factory()->createOne();
    $deniedSite = Site::factory()->createOne();
    $allowedPage = Page::factory()->site($allowedSite)->type($type)->create();
    $deniedPage = Page::factory()->site($deniedSite)->type($type)->create();
    $user = User::factory()->createOne();

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $allowedSite->getKey(),
    ]);

    expect($allowedPage->isAccessibleByUser($user))->toBeTrue()
        ->and($deniedPage->isAccessibleByUser($user))->toBeFalse();
});
