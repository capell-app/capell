<?php

declare(strict_types=1);

use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->freezeTime();
});

it('runs ordinary additional model listeners during a restore', function (string $event): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $parent->refresh()->delete();
    $called = [];
    Event::listen('eloquent.' . $event . ': ' . Page::class, static function (Page $page) use (&$called): void {
        $called[] = $page->id;
    });

    expect($parent->restore())->toBeTrue()
        ->and($called)->toBe([$parent->id, $child->id])
        ->and($child->fresh()->trashed())->toBeFalse();
})->with(['restoring', 'saving', 'updating']);

it('sends one committed PageSaved notification per restored member', function (bool $siteRestore): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    if ($siteRestore) {
        DeleteSiteAction::run($parent->site);
    } else {
        $parent->refresh()->delete();
    }

    $saved = [];
    $committedLevel = DB::transactionLevel();
    Event::listen(PageSaved::class, static function (PageSaved $event) use (&$saved): void {
        throw_unless($event->page instanceof Page, LogicException::class);
        $saved[] = [$event->page->id, DB::transactionLevel(), $event->page->isPageRestoreCascadePrepared()];
    });

    DB::transaction(function () use ($parent, $siteRestore, &$saved): void {
        expect($siteRestore ? RestoreSiteAction::run($parent->site->refresh()) : $parent->refresh()->restore())->toBeTrue()
            ->and($saved)->toBe([]);
    });

    expect($saved)->toBe([[$parent->id, $committedLevel, false], [$child->id, $committedLevel, false]]);
})->with(['subtree' => false, 'site' => true]);

it('bounds Core query work while restoring a two hundred page subtree', function (): void {
    $parent = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $ids = [$parent->id];
    for ($index = 1; $index < 200; $index++) {
        $child = Page::factory()->parent($parent)->createOne();
        $ids[] = $child->id;
        Translation::factory()->translatable($child)->language($language)->createOne(['title' => 'Large ' . $index]);
        PageUrl::factory()->page($child)->site($child->site)->language($language)->createOne(['url' => '/explicit-rereview-url-' . $index]);
    }

    $parent->refresh()->delete();
    $queries = 0;
    $restored = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });
    Event::listen('eloquent.restored: ' . Page::class, static function (Page $page) use (&$restored): void {
        $restored[] = $page->id;
    });

    expect($parent->refresh()->restore())->toBeTrue();

    expect($queries)->toBeLessThanOrEqual(2500)
        ->and($restored)->toBe($ids)
        ->and(Page::onlyTrashed()->whereKey($ids)->count())->toBe(0)
        ->and(Translation::onlyTrashed()->where('translatable_type', $parent->getMorphClass())->whereIn('translatable_id', $ids)->count())->toBe(0)
        ->and(PageUrl::onlyTrashed()->where('pageable_type', $parent->getMorphClass())->whereIn('pageable_id', $ids)->count())->toBe(0)
        ->and(Page::isBroken())->toBeFalse();
});

it('records a content revision on restore only when a listener changes captured content', function (bool $changeContent): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    Translation::factory()->translatable($page)->language($language)->createOne();
    $page->refresh()->save();
    $before = PageRevision::query()->where('page_uuid', $page->uuid)->count();
    expect($before)->toBeGreaterThan(0);
    $page->delete();
    if ($changeContent) {
        Page::saving(static function (Page $member): void {
            $member->name = 'Changed during restoration';
        });
    }

    expect($page->refresh()->restore())->toBeTrue()
        ->and(PageRevision::query()->where('page_uuid', $page->uuid)->count())->toBe($before + ($changeContent ? 1 : 0));
})->with([false, true]);

it('records subsequent related authoring changes on the restored model instance', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $translation = Translation::factory()->translatable($page)->language($language)->createOne();
    $page->refresh()->save();
    $page->delete();
    expect($page->restore())->toBeTrue();
    $before = PageRevision::query()->where('page_uuid', $page->uuid)->count();
    $translation->refresh()->updateQuietly(['title' => 'Edited after restoration']);

    event(new PageSaved($page));

    expect(PageRevision::query()->where('page_uuid', $page->uuid)->count())->toBe($before + 1);
});
