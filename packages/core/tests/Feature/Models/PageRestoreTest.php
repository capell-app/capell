<?php

declare(strict_types=1);

use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Exceptions\PageRestoreSlugConflictException;
use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\Event;

it('restores the exact cascade when descendant deletion crosses a second boundary', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $this->travelTo(today()->setTime(11, 0));
    Event::listen('eloquent.deleted: ' . Page::class, function (Page $page) use ($child): void {
        if ($page->is($child)) {
            $this->travel(1)->seconds();
        }
    });
    try {
        $parent->refresh()->delete();
    } finally {
        $this->travelBack();
    }

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $grandchild->id])->count())->toBe(3)
        ->and($child->fresh()->deleted_at->lt($parent->fresh()->deleted_at))->toBeTrue();
    $parent->refresh()->restore();

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $grandchild->id])->count())->toBe(0);
});

it('collects descendants deleted before the parent second for cascade authorisation', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $parent->delete();
    Page::withTrashed()->whereKey($parent->id)->update(['deleted_at' => $child->fresh()->deleted_at->addSecond()]);

    expect(CollectPageRestoreCascadeIdsAction::run($parent->refresh()))->toEqualCanonicalizing([$parent->id, $child->id]);
});

it('leaves independently deleted descendants trashed even in the same second', function (): void {
    $parent = Page::factory()->createOne();
    $independent = Page::factory()->parent($parent)->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $this->freezeTime();
    $independent->delete();
    $parent->refresh()->delete();

    expect($independent->fresh()->deleted_at->eq($parent->fresh()->deleted_at))->toBeTrue();
    $parent->refresh()->restore();

    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse()
        ->and($independent->fresh()->trashed())->toBeTrue();
});

it('retains the selected deletion cascade while restoring ancestors from a later operation', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $sibling = Page::factory()->parent($parent)->createOne();
    $this->travelTo(today()->setTime(11, 0));
    $child->refresh()->delete();
    $this->travel(1)->seconds();
    $parent->refresh()->delete();
    $this->travelBack();

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $grandchild->id, $sibling->id])->count())->toBe(4);
    $child->refresh()->restore();

    expect(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $grandchild->id, $sibling->id])->count())->toBe(0);
});

it('does not restore a cascade member independently deleted again while its parent remains trashed', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $this->freezeTime();
    $parent->delete();
    Page::withTrashed()->whereKey($child->id)->restore();
    $child->refresh()->delete();

    expect(CollectPageRestoreCascadeIdsAction::run($parent->refresh()))->toBe([$parent->id]);
    $parent->restore();

    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeTrue();
});

it('restores untracked legacy trash explicitly without guessing descendant membership', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    Page::query()->whereKey([$parent->id, $child->id])->update(['deleted_at' => now()]);

    expect(CollectPageRestoreCascadeIdsAction::run($parent->refresh()))->toBe([$parent->id]);
    $parent->restore();

    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeTrue();
    $child->refresh()->restore();
    expect($child->fresh()->trashed())->toBeFalse();
});

it('rolls back deletion records and descendant deletion when a cascade throws', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $originalBatches = DeletionBatch::query()->count();
    Event::listen('eloquent.deleted: ' . Page::class, function (Page $page) use ($child): void {
        throw_if($page->is($child), RuntimeException::class, 'Deletion interrupted.');
    });

    expect(fn (): ?bool => $parent->refresh()->delete())->toThrow(RuntimeException::class, 'Deletion interrupted.')
        ->and(Page::onlyTrashed()->whereKey([$parent->id, $child->id, $grandchild->id])->count())->toBe(0)
        ->and(DeletionBatch::query()->count())->toBe($originalBatches);
});

it('keeps force deletion on the native tree path without recording a restore cascade', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $child->delete();

    $originalBatches = DeletionBatch::query()->count();

    $parent->forceDelete();

    expect(Page::withTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(0)
        ->and(DeletionBatch::query()->count())->toBe($originalBatches);
});

it('restores page urls and translations with the page', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->withTranslations()->createOne(['language_id' => $language->getKey()]);
    $page = Page::factory()->site($site)->createOne();

    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->createOne(['title' => 'About Capell']);

    $pageUrl = PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->createOne(['url' => '/about']);

    $page->delete();

    expect($page->fresh()->trashed())->toBeTrue()
        ->and($pageUrl->fresh()->trashed())->toBeTrue()
        ->and($translation->fresh()->trashed())->toBeTrue();

    Page::query()->withTrashed()->whereKey($page->getKey())->firstOrFail()->restore();

    expect($page->fresh()->trashed())->toBeFalse()
        ->and($pageUrl->fresh()->trashed())->toBeFalse()
        ->and($translation->fresh()->trashed())->toBeFalse();
});

it('blocks restoring a page when a live page owns one of its old urls', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->withTranslations()->createOne(['language_id' => $language->getKey()]);
    $deletedPage = Page::factory()->site($site)->createOne(['name' => 'Deleted About']);
    $livePage = Page::factory()->site($site)->createOne(['name' => 'Live About']);

    PageUrl::factory()
        ->page($deletedPage)
        ->site($site)
        ->language($language)
        ->createOne(['url' => '/about']);

    $deletedPage->delete();

    PageUrl::factory()
        ->page($livePage)
        ->site($site)
        ->language($language)
        ->createOne(['url' => '/about']);

    expect(fn (): bool => Page::query()->withTrashed()->whereKey($deletedPage->getKey())->firstOrFail()->restore())
        ->toThrow(PageRestoreSlugConflictException::class);

    expect($deletedPage->fresh()->trashed())->toBeTrue();
});

it('restores trashed ancestors before restoring a child page', function (): void {
    $parent = Page::factory()->createOne(['name' => 'Parent']);
    $child = Page::factory()
        ->parent($parent)
        ->createOne([
            'name' => 'Child',
        ]);

    $child->delete();

    $parent->delete();

    expect($parent->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->trashed())->toBeTrue();

    Page::query()->withTrashed()->whereKey($child->getKey())->firstOrFail()->restore();

    expect($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->parent_id)->toBe($parent->getKey());
});
