<?php

declare(strict_types=1);

use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Exceptions\PageRestoreSlugConflictException;
use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\DeletionBatchRecord;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
        ->and(DeletionBatch::query()->count())->toBe($originalBatches - 1);
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

it('preserves cascade membership when a stale second parent instance deletes again', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $stale = Page::query()->whereKey($parent->id)->firstOrFail();
    $parent->refresh()->delete();
    $stale->delete();

    $parent->refresh()->restore();

    expect($child->fresh()->trashed())->toBeFalse()
        ->and(Page::isBroken())->toBeFalse();
})->group('restore-review');

it('retains moved subtree membership after restoring the original cascade root', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $parent->refresh()->delete();
    $child->refresh()->makeRoot()->save();
    $parent->refresh()->restore();

    expect($child->fresh()->trashed())->toBeTrue()
        ->and($grandchild->fresh()->trashed())->toBeTrue();
    $child->refresh()->restore();

    expect($grandchild->fresh()->trashed())->toBeFalse()
        ->and(Page::isBroken())->toBeFalse()
        ->and(DeletionBatchRecord::query()->where('model_type', Page::class)->count())->toBe(0)
        ->and(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(0);
})->group('restore-review');

it('restores child translations and urls through the cascade lifecycle', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $language = Language::factory()->english()->createOne();
    $translation = Translation::factory()->translatable($child)->language($language)->createOne(['title' => 'Child']);
    $url = PageUrl::factory()->page($child)->site($child->site)->language($language)->createOne(['url' => '/cascade-child']);
    $parent->refresh()->delete();
    $restored = [];
    Event::listen('eloquent.restored: ' . Page::class, static function (Page $page) use (&$restored): void {
        $restored[] = $page->id;
    });
    $parent->refresh()->restore();

    expect($translation->fresh()->trashed())->toBeFalse()
        ->and($url->fresh()->trashed())->toBeFalse()
        ->and($restored)->toEqualCanonicalizing([$parent->id, $child->id]);
})->group('restore-review');

it('prunes restored page batches and removes purged cascade memberships', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $parent->refresh()->delete();
    $parent->restore();
    $parent->refresh()->delete();
    $parent->refresh()->forceDelete();

    expect(DeletionBatchRecord::query()->where('model_type', Page::class)->count())->toBe(0)
        ->and(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(0)
        ->and(Page::withTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(0)
        ->and(Page::isBroken())->toBeFalse();
})->group('restore-review');

it('keeps standard cascade relation queries bounded while firing each member lifecycle', function (int $size): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $ids = [$parent->id];
    for ($index = 1; $index < $size; $index++) {
        $child = Page::factory()->parent($parent)->createOne();
        $ids[] = $child->id;
        Translation::factory()->translatable($child)->language($language)->createOne(['title' => 'Child ' . $index]);
        PageUrl::factory()->page($child)->site($child->site)->language($language)->createOne(['url' => '/bounded-' . $index]);
    }

    $parent->refresh()->delete();
    $restored = [];
    Event::listen('eloquent.restored: ' . Page::class, static function (Page $page) use (&$restored): void {
        $restored[] = $page->id;
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $parent->refresh()->restore();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    $grammar = DB::connection()->getQueryGrammar();
    $relationQueries = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'update ' . $grammar->wrapTable('page_urls'))
        || str_starts_with($query['query'], 'update ' . $grammar->wrapTable('translations'))
        || (str_contains($query['query'], 'from ' . $grammar->wrapTable('page_urls'))
            && (str_contains($query['query'], $grammar->wrap('page_urls.deleted_at') . ' is not null')
                || str_contains($query['query'], $grammar->wrap('url') . ' in'))));
    expect($restored)->toEqualCanonicalizing($ids)
        ->and(count($relationQueries))->toBe(4)
        // Aggregate revision capture and activity logging still run for each member.
        ->and(count($queries))->toBeLessThanOrEqual(20 + 30 * $size)
        ->and(Page::isBroken())->toBeFalse();
})->with([2, 30]);

it('rolls back every member and its relations when a descendant restoring listener refuses', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $language = Language::factory()->english()->createOne();
    $translation = Translation::factory()->translatable($child)->language($language)->createOne();
    $parent->refresh()->delete();
    Event::listen('eloquent.restoring: ' . Page::class, static fn (Page $member): ?bool => $member->is($child) ? false : null);

    expect($parent->refresh()->restore())->toBeFalse()
        ->and($parent->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->trashed())->toBeTrue()
        ->and($translation->fresh()->trashed())->toBeTrue()
        ->and(DeletionBatchRecord::query()->where('model_type', Page::class)->count())->toBe(2);
});

it('consumes membership when members are restored individually before the remaining batch', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $parent->refresh()->delete();
    $child->refresh()->makeRoot()->save();
    $child->refresh()->restore();

    expect(DeletionBatchRecord::query()->where('model_type', Page::class)->pluck('model_id')->all())->toBe([$parent->id])
        ->and($grandchild->fresh()->trashed())->toBeFalse();
    $parent->refresh()->restore();
    expect(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(0);
});

it('preserves remaining members when one recorded subtree is permanently purged', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $grandchild = Page::factory()->parent($child)->createOne();
    $sibling = Page::factory()->parent($parent)->createOne();
    $parent->refresh()->delete();
    $child->refresh()->forceDelete();

    expect(DeletionBatchRecord::query()->where('model_type', Page::class)->pluck('model_id')->all())->toEqualCanonicalizing([$parent->id, $sibling->id])
        ->and(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(1);
    $parent->refresh()->restore();
    expect($sibling->fresh()->trashed())->toBeFalse()
        ->and(Page::withTrashed()->whereKey([$child->id, $grandchild->id])->count())->toBe(0)
        ->and(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(0)
        ->and(Page::isBroken())->toBeFalse();
});

it('removes purged page memberships from site batches without removing other members', function (): void {
    $this->freezeTime();
    $page = Page::factory()->createOne();
    $batch = DeletionBatch::query()->create(['root_type' => Site::class, 'root_id' => $page->site_id]);
    $batch->records()->create(['model_type' => Page::class, 'model_id' => $page->id]);
    $batch->records()->create(['model_type' => Site::class, 'model_id' => $page->site_id]);
    $page->forceDelete();

    expect($batch->records()->where('model_type', Page::class)->count())->toBe(0)
        ->and($batch->records()->where('model_type', Site::class)->count())->toBe(1)
        ->and($batch->fresh())->not->toBeNull();
});

it('ignores closed page history when choosing the latest remaining deletion membership', function (): void {
    $this->freezeTime();
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $parent->refresh()->delete();
    $history = DeletionBatch::query()->create(['root_type' => Page::class, 'root_id' => $parent->id, 'restored_at' => now()]);
    foreach ([$parent->id, $child->id] as $id) {
        $history->records()->create(['model_type' => Page::class, 'model_id' => $id]);
    }

    expect(CollectPageRestoreCascadeIdsAction::run($parent->refresh()))->toEqualCanonicalizing([$parent->id, $child->id]);
    $parent->restore();
    expect($child->fresh()->trashed())->toBeFalse()
        ->and(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(0);
});

it('prunes page-owned membership when a site restores its recorded pages', function (): void {
    $this->freezeTime();
    $site = Site::factory()->createOne();
    $parent = Page::factory()->site($site)->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    DeleteSiteAction::run($site);
    RestoreSiteAction::run($site->refresh());

    expect(DeletionBatch::query()->where('root_type', Page::class)->count())->toBe(0)
        ->and(DeletionBatchRecord::query()->where('model_type', Page::class)->whereHas('batch', fn (Builder $query): Builder => $query->where('root_type', Site::class))->count())->toBe(2)
        ->and($parent->fresh()->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse();
});
