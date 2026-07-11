<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BulkMovePagesAction;
use Capell\Admin\Policies\PagePolicy;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    Gate::policy(Page::class, PagePolicy::class);
    Gate::before(fn (mixed $user, string $ability): ?bool => $user->hasRole('super_admin') ? true : null);
});

it('returns zero counts for empty collections', function (): void {
    $actor = test()->createUserWithRole('super_admin');

    $parentPage = Mockery::mock(Page::class)->makePartial();
    $pages = new Collection;

    $result = BulkMovePagesAction::run($pages, $parentPage, $actor);

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(0);

    Mockery::close();
});

it('skips a page when the new parent is the page itself', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $page = Page::factory()->createOne();
    $originalParentId = $page->parent_id;

    $result = BulkMovePagesAction::run(new Collection([$page]), $page, $actor);

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($page->fresh()->parent_id)->toBe($originalParentId);
});

it('skips a page when the new parent is a descendant of the page (would create a cycle)', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $page = Page::factory()->recycle($site)->create();
    $child = Page::factory()->recycle($site)->parent($page)->create();
    $originalParentId = $page->parent_id;

    $result = BulkMovePagesAction::run(new Collection([$page]), $child, $actor);

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($page->fresh()->parent_id)->toBe($originalParentId);
});

it('moves valid pages and skips ones that would create a cycle', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $ancestor = Page::factory()->recycle($site)->create();
    $descendant = Page::factory()->recycle($site)->parent($ancestor)->create();
    $movable = Page::factory()->recycle($site)->create();

    $result = BulkMovePagesAction::run(
        new Collection([$movable, $ancestor]),
        $descendant,
        $actor,
    );

    expect($result['moved'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and($movable->fresh()->parent_id)->toBe($descendant->getKey())
        ->and($ancestor->fresh()->parent_id)->toBeNull();
});

it('does not add redirects when the $addRedirects flag is false', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->create();
    $page = Page::factory()->recycle($site)->create();

    $redirectCountBefore = PageUrl::query()->where('type', UrlTypeEnum::Redirect)->count();

    $result = BulkMovePagesAction::run(new Collection([$page]), $newParent, $actor, false);

    expect($result['moved'])->toBe(1)
        ->and($result['redirects'])->toBe(0)
        ->and(PageUrl::query()->where('type', UrlTypeEnum::Redirect)->count())->toBe($redirectCountBefore);
});

it('creates redirects from previous URLs of the moved page when $addRedirects is true', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();

    $oldUrls = $page->fresh()->pageUrls()
        ->whereNull('type')
        ->pluck('url')
        ->all();

    expect($oldUrls)->not->toBeEmpty();

    $result = BulkMovePagesAction::run(new Collection([$page]), $newParent, $actor, true);

    expect($result['moved'])->toBe(1)
        ->and($result['redirects'])->toBeGreaterThan(0);

    foreach ($oldUrls as $oldUrl) {
        $redirect = PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('url', $oldUrl)
            ->where('type', UrlTypeEnum::Redirect)
            ->first();
        $redirect = expectPresent($redirect);

        expect($redirect)->not->toBeNull()
            ->and($redirect->pageable_id)->toBe($page->getKey());
    }
});

it('creates redirects for descendants when $addRedirects is true', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();
    $child = Page::factory()->recycle($site)->parent($page)->withTranslations()->create();

    $childOldUrls = $child->fresh()->pageUrls()
        ->whereNull('type')
        ->pluck('url')
        ->all();

    expect($childOldUrls)->not->toBeEmpty();

    $result = BulkMovePagesAction::run(new Collection([$page]), $newParent, $actor, true);

    expect($result['moved'])->toBe(1);

    foreach ($childOldUrls as $oldUrl) {
        $redirect = PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('url', $oldUrl)
            ->where('type', UrlTypeEnum::Redirect)
            ->first();
        $redirect = expectPresent($redirect);

        expect($redirect)->not->toBeNull()
            ->and($redirect->pageable_id)->toBe($child->getKey());
    }
});

it('skips creating a redirect when the URL already exists for the site and language', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();

    $alias = $page->fresh()->pageUrls()->whereNull('type')->first();
    expect($alias)->not->toBeNull();
    $collidingUrl = (string) $alias->url;
    $languageId = (int) $alias->language_id;

    $otherPage = Page::factory()->recycle($site)->create();
    PageUrl::withoutEvents(fn (): PageUrl => PageUrl::query()->create([
        'site_id' => $site->getKey(),
        'language_id' => $languageId,
        'url' => $collidingUrl,
        'type' => UrlTypeEnum::Redirect,
        'pageable_id' => $otherPage->getKey(),
        'pageable_type' => $otherPage->getMorphClass(),
        'is_manual' => true,
    ]));

    $redirectsForUrlBefore = PageUrl::query()
        ->where('site_id', $site->getKey())
        ->where('language_id', $languageId)
        ->where('url', $collidingUrl)
        ->where('type', UrlTypeEnum::Redirect)
        ->count();

    $result = BulkMovePagesAction::run(new Collection([$page]), $newParent, $actor, true);

    expect($result['moved'])->toBe(1);

    $redirectsForUrlAfter = PageUrl::query()
        ->where('site_id', $site->getKey())
        ->where('language_id', $languageId)
        ->where('url', $collidingUrl)
        ->where('type', UrlTypeEnum::Redirect)
        ->count();

    expect($redirectsForUrlAfter)->toBe($redirectsForUrlBefore);
});

it('skips redirect creation when the old URL equals the new URL (no-op move)', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $parent = Page::factory()->recycle($site)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->parent($parent)->withTranslations()->create();

    $result = BulkMovePagesAction::run(new Collection([$page]), $parent, $actor, true);

    expect($result['moved'])->toBe(1)
        ->and($result['redirects'])->toBe(0);
});

it('correctly detects cycles with deep parent chains', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();

    // Create a deep hierarchy: root → level1 → level2 → level3
    $root = Page::factory()->recycle($site)->create();
    $level1 = Page::factory()->recycle($site)->parent($root)->create();
    $level2 = Page::factory()->recycle($site)->parent($level1)->create();
    $level3 = Page::factory()->recycle($site)->parent($level2)->create();

    $otherPage = Page::factory()->recycle($site)->create();

    // Try to move root under level3 (would create cycle through the deep chain)
    $result = BulkMovePagesAction::run(new Collection([$root]), $level3, $actor);

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($root->fresh()->parent_id)->toBeNull();
});

it('moves pages to unrelated pages in the same site', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();

    // Create two separate branches
    $branch1Root = Page::factory()->recycle($site)->create();
    $branch1Child = Page::factory()->recycle($site)->parent($branch1Root)->create();

    $branch2Root = Page::factory()->recycle($site)->create();
    $branch2Child = Page::factory()->recycle($site)->parent($branch2Root)->create();

    // Move branch1Child under branch2Root (should succeed, no cycle)
    $result = BulkMovePagesAction::run(
        new Collection([$branch1Child]),
        $branch2Root,
        $actor,
    );

    expect($result['moved'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and($branch1Child->fresh()->parent_id)->toBe($branch2Root->getKey());
});

it('skips pages when the new parent belongs to another site', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $sourceSite = Site::factory()->withTranslations()->create();
    $targetSite = Site::factory()->withTranslations()->create();
    $page = Page::factory()->recycle($sourceSite)->create();
    $newParent = Page::factory()->recycle($targetSite)->create();

    $result = BulkMovePagesAction::run(
        new Collection([$page]),
        $newParent,
        $actor,
    );

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($page->fresh()->parent_id)->toBeNull();
});

it('skips pages when the actor cannot update the new parent', function (): void {
    Permission::findOrCreate('Update:Page');

    $actor = test()->createUserWithPermission('Update:Page');
    $site = Site::factory()->withTranslations()->create();
    $page = Page::factory()->recycle($site)->create();
    $restrictedType = Blueprint::factory()->page()->create();
    $restrictedRole = Role::create(['name' => 'restricted-parent-editor', 'guard_name' => 'web']);
    $restrictedType->roleRestrictions()->create(['role_id' => $restrictedRole->id]);
    $newParent = Page::factory()->recycle($site)->create(['blueprint_id' => $restrictedType->getKey()]);

    $result = BulkMovePagesAction::run(
        new Collection([$page]),
        $newParent,
        $actor,
    );

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($page->fresh()->parent_id)->toBeNull();
});

it('correctly walks parent chain when parent() relationship has constraints', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();

    // Create a chain: root → intermediate → target
    $root = Page::factory()->recycle($site)->withTranslations()->create();
    $intermediate = Page::factory()->recycle($site)->withTranslations()->parent($root)->create();
    $target = Page::factory()->recycle($site)->withTranslations()->parent($intermediate)->create();

    $unrelatedPage = Page::factory()->recycle($site)->create();

    // Try to move root under target
    // This tests that cycle detection walks the parent chain correctly
    // even when the parent() relationship has language/other constraints
    $result = BulkMovePagesAction::run(
        new Collection([$root]),
        $target,
        $actor,
    );

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($root->fresh()->parent_id)->toBeNull();
});

it('allows moving to unrelated root pages', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();

    $rootA = Page::factory()->recycle($site)->create();
    $childOfA = Page::factory()->recycle($site)->parent($rootA)->create();

    $rootB = Page::factory()->recycle($site)->create();

    // Move childOfA under rootB (different root, should succeed)
    $result = BulkMovePagesAction::run(
        new Collection([$childOfA]),
        $rootB,
        $actor,
    );

    expect($result['moved'])->toBe(1)
        ->and($childOfA->fresh()->parent_id)->toBe($rootB->getKey());
});

it('skips pages that would create indirect cycles through intermediate parents', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();

    // Create: grandparent → parent → child
    $grandparent = Page::factory()->recycle($site)->create();
    $parent = Page::factory()->recycle($site)->parent($grandparent)->create();
    $child = Page::factory()->recycle($site)->parent($parent)->create();

    // Try to move grandparent under child (indirect cycle through parent)
    $result = BulkMovePagesAction::run(
        new Collection([$grandparent]),
        $child,
        $actor,
    );

    expect($result['moved'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($grandparent->fresh()->parent_id)->toBeNull();
});

it('rolls back the whole batch when a move fails mid-transaction', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->create();
    $firstPage = Page::factory()->recycle($site)->create(['name' => 'First page']);
    $failingPage = Page::factory()->recycle($site)->create(['name' => 'Failing page']);

    Page::saving(function (Page $page): void {
        throw_if($page->name === 'Failing page' && $page->parent_id !== null, RuntimeException::class, 'planned move failure');
    });

    $result = BulkMovePagesAction::run(
        new Collection([$firstPage, $failingPage]),
        $newParent,
        $actor,
    );

    expect($result['moved'])->toBe(0)
        ->and($result['redirects'])->toBe(0)
        ->and($result['failed_at'])->toMatchArray([
            'id' => $failingPage->getKey(),
            'name' => 'Failing page',
            'reason' => 'planned move failure',
        ])
        ->and($firstPage->fresh()->parent_id)->toBeNull()
        ->and($failingPage->fresh()->parent_id)->toBeNull();
});
