<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Actions\BulkMovePagesBulkAction;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Policies\PagePolicy;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    Gate::policy(Page::class, PagePolicy::class);
    Gate::before(fn (mixed $user, string $ability): ?bool => $user->hasRole('super_admin') ? true : null);

    test()->actingAsAdmin();
});

it('bulk move pages action moves selected pages under chosen parent', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->create();
    $pages = Page::factory()->recycle($site)->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => $newParent->getKey()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    foreach ($pages as $page) {
        expect($page->fresh()->parent_id)->toBe($newParent->getKey());
    }
});

it('bulk move pages action requires a parent', function (): void {
    $pages = Page::factory()->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => null],
        )
        ->assertHasActionErrors(['parent_id' => 'required']);
});

it('bulk move pages action rejects a non-existent parent via form validation', function (): void {
    $pages = Page::factory()->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => 999_999],
        )
        ->assertHasActionErrors(['parent_id']);

    foreach ($pages as $page) {
        expect($page->fresh()->parent_id)->toBe($page->parent_id);
    }
});

it('bulk move pages action refuses to assign a page as its own parent', function (): void {
    $page = Page::factory()->createOne();
    $originalParentId = $page->parent_id;

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$page])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => $page->getKey()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::bulk_actions.move_pages_none_moved'));

    expect($page->fresh()->parent_id)->toBe($originalParentId);
});

it('bulk move pages action refuses a parent that is a descendant of the moved page', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $page = Page::factory()->recycle($site)->create();
    $child = Page::factory()->recycle($site)->parent($page)->create();
    $originalParentId = $page->parent_id;

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$page])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => $child->getKey()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::bulk_actions.move_pages_none_moved'));

    expect($page->fresh()->parent_id)->toBe($originalParentId);
});

it('bulk move pages action creates redirects when the add_redirects checkbox is checked', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();

    $oldUrls = $page->fresh()->pageUrls()
        ->whereNull('type')
        ->pluck('url')
        ->all();

    expect($oldUrls)->not->toBeEmpty();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$page])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: [
                'parent_id' => $newParent->getKey(),
                'add_redirects' => true,
            ],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($page->fresh()->parent_id)->toBe($newParent->getKey());

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

it('bulk move pages action does not create redirects when the add_redirects checkbox is unchecked', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $newParent = Page::factory()->recycle($site)->create();
    $page = Page::factory()->recycle($site)->create();

    $redirectsBefore = PageUrl::query()->where('type', UrlTypeEnum::Redirect)->count();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$page])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: [
                'parent_id' => $newParent->getKey(),
                'add_redirects' => false,
            ],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(PageUrl::query()->where('type', UrlTypeEnum::Redirect)->count())
        ->toBe($redirectsBefore);
});

it('bulk move pages with translations to unrelated parent succeeds', function (): void {
    $site = Site::factory()->withTranslations()->create();

    // Create two separate branches with translations
    $branchA = Page::factory()->recycle($site)->withTranslations()->create();
    $childOfA = Page::factory()->recycle($site)->withTranslations()->parent($branchA)->create();

    $branchB = Page::factory()->recycle($site)->withTranslations()->create();

    // Move childOfA under branchB (tests cycle detection with parent() relationship constraints)
    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$childOfA])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => $branchB->getKey()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($childOfA->fresh()->parent_id)->toBe($branchB->getKey());
});

it('bulk move pages correctly detects cycles with deep parent chains', function (): void {
    $site = Site::factory()->withTranslations()->create();

    // Create a deep hierarchy: root → level1 → level2 → level3
    $root = Page::factory()->recycle($site)->create();
    $level1 = Page::factory()->recycle($site)->parent($root)->create();
    $level2 = Page::factory()->recycle($site)->parent($level1)->create();
    $level3 = Page::factory()->recycle($site)->parent($level2)->create();

    $originalRootParent = $root->parent_id;

    // Try to move root under level3 (would create cycle)
    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$root])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => $level3->getKey()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::bulk_actions.move_pages_none_moved'));

    expect($root->fresh()->parent_id)->toBe($originalRootParent);
});

it('bulk move mixed valid and invalid pages reports correct counts', function (): void {
    $site = Site::factory()->withTranslations()->create();

    $validTarget = Page::factory()->recycle($site)->create();
    $validPage = Page::factory()->recycle($site)->create();

    // Create a cycle scenario: ancestor and its child
    $ancestor = Page::factory()->recycle($site)->create();
    $descendant = Page::factory()->recycle($site)->parent($ancestor)->create();

    // Try to move both valid page and ancestor (which would create cycle)
    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$validPage, $ancestor])
        ->callAction(
            TestAction::make(BulkMovePagesBulkAction::class)->table()->bulk(),
            data: ['parent_id' => $descendant->getKey()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::bulk_actions.move_pages_done', ['moved' => 1, 'skipped' => 1]));

    // Only validPage should be moved (ancestor creates cycle)
    expect($validPage->fresh()->parent_id)->toBe($descendant->getKey())
        ->and($ancestor->fresh()->parent_id)->toBeNull();
});
