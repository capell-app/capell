<?php

declare(strict_types=1);

use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Events\PageSaved;
use Capell\Core\Exceptions\PageRestoreSlugConflictException;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Models\DeletionBatchRecord;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Tests\Support\RestoreExternalIndexListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->freezeTime();
    RestoreExternalIndexListener::$writes = [];
});

it('leaves no external lifecycle work when a later descendant refuses restoration', function (): void {
    $parent = Page::factory()->createOne();
    $first = Page::factory()->parent($parent)->createOne();
    $last = Page::factory()->parent($parent)->createOne();
    $parent->refresh()->delete();
    config()->set('queue.default', 'sync');
    Event::listen(PageSaved::class, RestoreExternalIndexListener::class);
    Event::listen('eloquent.restoring: ' . Page::class, static fn (Page $member): ?bool => $member->is($last) ? false : null);

    expect($parent->refresh()->restore())->toBeFalse()
        ->and(Page::onlyTrashed()->whereKey([$parent->id, $first->id, $last->id])->count())->toBe(3)
        ->and(RestoreExternalIndexListener::$writes)->toBe([]);
})->group('restore-final');

it('restores routes that do not occupy the same enabled site and language scope', function (string $difference): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $url = PageUrl::factory()->page($page)->site($page->site)->language($language)->createOne(['url' => '/scoped-route']);
    $page->delete();
    $site = $difference === 'site' ? Site::factory()->createOne() : $page->site;
    $otherLanguage = $difference === 'language' ? Language::factory()->createOne(['code' => 'cy']) : $language;
    $owner = Page::factory()->site($site)->createOne();
    PageUrl::factory()->page($owner)->site($site)->language($otherLanguage)->createOne(['url' => '/scoped-route', 'status' => $difference !== 'disabled']);

    expect($page->restore())->toBeTrue()->and($url->fresh()->trashed())->toBeFalse();
})->with(['site', 'language', 'disabled'])->group('restore-final');

it('refuses duplicate enabled route rows even when both belong to the same page', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    PageUrl::factory()->page($page)->site($page->site)->language($language)->createOne(['url' => '/same-owner']);
    $page->delete();
    PageUrl::factory()->page($page)->site($page->site)->language($language)->createOne(['url' => '/same-owner', 'is_manual' => true]);

    expect(fn (): bool => $page->restore())->toThrow(PageRestoreSlugConflictException::class)
        ->and($page->fresh()->trashed())->toBeTrue();
})->group('restore-final');

it('refuses site restoration when a recorded manual route has been replaced', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $old = PageUrl::factory()->site($site)->language($language)->createOne(['is_manual' => true, 'pageable_type' => null, 'pageable_id' => null, 'url' => '/manual-route']);
    DeleteSiteAction::run($site);
    PageUrl::factory()->site($site)->language($language)->createOne(['is_manual' => true, 'pageable_type' => null, 'pageable_id' => null, 'url' => '/manual-route']);

    expect(fn (): bool => RestoreSiteAction::run($site->refresh()))->toThrow(PageUrlCollisionException::class)
        ->and($site->fresh()->trashed())->toBeTrue()
        ->and($old->fresh()->trashed())->toBeTrue();
})->group('restore-final');

it('discards lifecycle notifications when an outer transaction rolls back', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $parent->refresh()->delete();
    config()->set('queue.default', 'sync');
    Event::listen(PageSaved::class, RestoreExternalIndexListener::class);

    expect(function () use ($parent): void {
        DB::transaction(function () use ($parent): void {
            expect($parent->refresh()->restore())->toBeTrue();
            throw new RuntimeException('Outer operation refused.');
        });
    })->toThrow(RuntimeException::class, 'Outer operation refused.');

    expect(RestoreExternalIndexListener::$writes)->toBe([])
        ->and($parent->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->trashed())->toBeTrue();
})->group('restore-final');

it('restores identical routes in different languages within the same cascade', function (): void {
    $parent = Page::factory()->createOne();
    $first = Page::factory()->parent($parent)->createOne();
    $second = Page::factory()->parent($parent)->createOne();
    $english = Language::factory()->english()->createOne();
    $other = Language::factory()->createOne(['code' => 'cy', 'name' => 'Welsh']);
    $firstUrl = PageUrl::factory()->page($first)->site($parent->site)->language($english)->createOne(['url' => '/shared-route']);
    $secondUrl = PageUrl::factory()->page($second)->site($parent->site)->language($other)->createOne(['url' => '/shared-route']);
    $parent->refresh()->delete();

    expect($parent->refresh()->restore())->toBeTrue()
        ->and($firstUrl->fresh()->trashed())->toBeFalse()
        ->and($secondUrl->fresh()->trashed())->toBeFalse()
        ->and(Page::onlyTrashed()->whereKey([$parent->id, $first->id, $second->id])->count())->toBe(0);
})->group('restore-final');

it('keeps quiet restoration silent when its outer transaction commits later', function (): void {
    $page = Page::factory()->createOne();
    $page->delete();
    Event::listen(PageSaved::class, RestoreExternalIndexListener::class);

    DB::transaction(function () use ($page): void {
        expect($page->restoreQuietly())->toBeTrue();
    });

    expect($page->fresh()->trashed())->toBeFalse()
        ->and(RestoreExternalIndexListener::$writes)->toBe([]);
})->group('restore-final');

it('does not reuse site membership after a page was restored independently and deleted again', function (): void {
    $site = Site::factory()->createOne();
    $page = Page::factory()->site($site)->createOne();
    DeleteSiteAction::run($site);
    expect($page->refresh()->restore())->toBeTrue();
    $page->refresh()->delete();

    expect(RestoreSiteAction::run($site->refresh()))->toBeTrue()
        ->and($page->fresh()->trashed())->toBeTrue()
        ->and(CollectPageRestoreCascadeIdsAction::run($page->refresh()))->toBe([$page->id]);
})->group('restore-final');

it('rolls back pages relations and membership when a later model listener throws', function (): void {
    $parent = Page::factory()->createOne();
    $child = Page::factory()->parent($parent)->createOne();
    $language = Language::factory()->english()->createOne();
    $translation = Translation::factory()->translatable($child)->language($language)->createOne();
    $url = PageUrl::factory()->page($child)->site($child->site)->language($language)->createOne(['url' => '/throwing-child']);
    $parent->refresh()->delete();
    Event::listen(PageSaved::class, RestoreExternalIndexListener::class);
    Event::listen('eloquent.restoring: ' . Page::class, static function (Page $page) use ($child): void {
        throw_if($page->is($child), RuntimeException::class, 'Listener interrupted restoration.');
    });

    expect(fn (): bool => $parent->restore())->toThrow(RuntimeException::class, 'Listener interrupted restoration.')
        ->and(Page::onlyTrashed()->whereKey([$parent->id, $child->id])->count())->toBe(2)
        ->and($translation->fresh()->trashed())->toBeTrue()
        ->and($url->fresh()->trashed())->toBeTrue()
        ->and(DeletionBatchRecord::query()->where('model_type', Page::class)->count())->toBe(2)
        ->and(RestoreExternalIndexListener::$writes)->toBe([]);
});
