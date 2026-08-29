<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\EventSourcing\Events\PageRevisionRecorded;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\EventSourcing\Exceptions\RollbackBlocked;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\Actions\BuildRollbackPreviewAction;
use Capell\Core\EventSourcing\Rollback\RollbackIssueData;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Url\PageUrlRewriteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function recordRevisionFor(Page $page): void
{
    // Re-fire the recording bridge the way a real save would: reload relations,
    // then save so PageSaved is dispatched and a revision is captured.
    $page->load(['translations', 'pageUrls']);
    $page->save();
}

it('records the first revision after a page owns its authoring relationships', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    Translation::factory()
        ->translatable($page)
        ->language($language)
        ->createOne();

    recordRevisionFor($page);

    $revisionEvents = DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRevisionRecorded::class)
        ->count();

    expect($revisionEvents)->toBe(1);
    expect(PageRevision::query()->where('page_uuid', $page->uuid)->exists())->toBeTrue();
});

it('round-trips a page with translations and urls through the serializer', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'Original', 'content' => '<p>original body</p>']);

    PageUrl::factory()->create([
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->getKey(),
        'site_id' => $page->site_id,
        'language_id' => $language->id,
        'url' => 'original-url',
    ]);

    $serializer = resolve(PageStateSerializer::class);
    $captured = $serializer->capture($page);

    // Mutate, then restore the captured state and re-capture.
    $page->translations()->first()->forceFill([
        'title' => 'Changed',
        'content' => '<p>changed body</p>',
    ])->save();

    $serializer->restore($page->fresh(), $captured);

    expect($serializer->capture($page->fresh()))->toEqual($captured);
});

it('rebuilds the canonical url when restoring a revision captured before url creation', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    Translation::factory()->translatable($page)->language($language)
        ->slug('original-url')
        ->create(['title' => 'Original', 'content' => '<p>original body</p>']);

    $serializer = resolve(PageStateSerializer::class);
    $captured = $serializer->capture($page);
    $captured['pageUrls'] = [];

    $page->pageUrls()->firstOrFail()->forceFill(['url' => '/changed-url'])->save();

    $serializer->restore($page->fresh(), $captured);

    $resolution = ResolvePublicPageByUrlAction::run($page->site, $language, '/original-url');

    expect($resolution->found())->toBeTrue()
        ->and($resolution->page?->getKey())->toBe($page->getKey())
        ->and($resolution->fields->url)->toBe('/original-url');
});

it('surfaces an empty-url-snapshot collision as a blocked rollback', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $pageA = Page::factory()->site($site)->createOne(['name' => 'Page A']);

    $translationA = Model::withoutEvents(
        fn (): Translation => Translation::factory()
            ->translatable($pageA)
            ->language($language)
            ->slug('shared-url')
            ->createOne(['title' => 'Page A']),
    );

    recordRevisionFor($pageA);
    $targetVersion = resolve(RollbackService::class)->currentVersion($pageA->uuid);

    SetupPageUrlsAction::run($pageA, updateDescendants: false);
    resolve(PageUrlRewriteContext::class)->withoutAutomaticRedirects(function () use ($translationA): void {
        $translationA->forceFill([
            'meta' => [...($translationA->meta ?? []), 'slug' => 'moved-url'],
        ])->save();
    });
    recordRevisionFor($pageA);

    $pageB = Page::factory()->site($site)->createOne(['name' => 'Page B']);
    Translation::factory()
        ->translatable($pageB)
        ->language($language)
        ->slug('shared-url')
        ->createOne(['title' => 'Page B']);

    $targetState = resolve(RollbackService::class)->targetStateAt($pageA->uuid, $targetVersion);
    $preview = BuildRollbackPreviewAction::run($pageA->fresh(), $targetVersion);
    $versionBeforeApply = resolve(RollbackService::class)->currentVersion($pageA->uuid);

    expect($targetState['pageUrls'] ?? null)->toBe([])
        ->and($preview->isBlocked())->toBeFalse()
        ->and($pageA->pageUrls()->where('url', '/moved-url')->exists())->toBeTrue()
        ->and($pageB->pageUrls()->where('url', '/shared-url')->exists())->toBeTrue();

    $rollbackBlocked = null;

    try {
        ApplyRollbackAction::run($pageA->fresh(), $targetVersion);
    } catch (RollbackBlocked $exception) {
        $rollbackBlocked = $exception;
    }

    /** @var RollbackIssueData|null $blockingIssue */
    $blockingIssue = $rollbackBlocked?->issues[0] ?? null;

    $sharedUrls = PageUrl::query()
        ->where('site_id', $site->getKey())
        ->where('language_id', $language->getKey())
        ->where('url', '/shared-url')
        ->get();

    $sharedResolution = ResolvePublicPageByUrlAction::run($site, $language, '/shared-url');
    $movedResolution = ResolvePublicPageByUrlAction::run($site, $language, '/moved-url');

    expect($sharedUrls)->toHaveCount(1)
        ->and($rollbackBlocked)->toBeInstanceOf(RollbackBlocked::class)
        ->and($blockingIssue?->code)->toBe('page_url_conflict')
        ->and($blockingIssue?->message)->toBe("The URL '/shared-url' is already in use by another page.")
        ->and($blockingIssue?->path)->toBe('pageUrls./shared-url')
        ->and(resolve(RollbackService::class)->currentVersion($pageA->uuid))->toBe($versionBeforeApply)
        ->and(DB::table('stored_events')
            ->where('aggregate_uuid', $pageA->uuid)
            ->where('event_class', PageRolledBack::class)
            ->count())->toBe(0)
        ->and($sharedUrls->sole()->pageable_id)->toBe($pageB->getKey())
        ->and($sharedResolution->found())->toBeTrue()
        ->and($sharedResolution->page?->getKey())->toBe($pageB->getKey())
        ->and($movedResolution->found())->toBeTrue()
        ->and($movedResolution->page?->getKey())->toBe($pageA->getKey());
});

it('previews and applies a rollback that restores earlier content', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordRevisionFor($page);

    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordRevisionFor($page);

    $preview = BuildRollbackPreviewAction::run($page->fresh(), $targetVersion);
    expect($preview->isBlocked())->toBeFalse();
    expect($preview->hasChanges())->toBeTrue();

    Event::fake([FrontendSurrogateKeysInvalidated::class]);
    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    expect($translation->fresh()->title)->toBe('First');
    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === ['page-' . $page->getKey()],
    );
    expect(DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRolledBack::class)
        ->count())->toBe(1);
});

it('restores the content_structure_override when rolling back across a mode switch', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    Translation::factory()
        ->translatable($page)
        ->language($language)
        ->createOne();

    $page->forceFill(['content_structure_override' => ContentStructure::Html->value])->save();
    recordRevisionFor($page);

    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    // Editor flips the page from HTML to Blocks, then records a revision.
    $page->forceFill(['content_structure_override' => ContentStructure::Blocks->value])->save();
    recordRevisionFor($page);

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    expect($page->fresh()->getAttributeFromArray('content_structure_override'))
        ->toBe(ContentStructure::Html->value);
});

it('preserves live pageUrl analytics across a rollback', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    Model::withoutEvents(
        fn (): Translation => Translation::factory()
            ->translatable($page)
            ->language($language)
            ->slug('analytics-url')
            ->createOne(),
    );

    $pageUrl = PageUrl::factory()->create([
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->getKey(),
        'site_id' => $page->site_id,
        'language_id' => $language->id,
        'url' => 'analytics-url',
        'hit_count' => 5,
    ]);
    recordRevisionFor($page);

    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    // The url accrues real visits after the snapshot; unrelated content churns.
    $pageUrl->forceFill(['hit_count' => 42])->save();
    $page->forceFill(['name' => 'Renamed'])->save();
    recordRevisionFor($page);

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    // Rollback restores content but must NOT regress the live visit count back
    // to the snapshot's value of 5.
    expect($pageUrl->fresh()->hit_count)->toBe(42);
});

it('blocks a rollback whose url would collide with another page', function (): void {
    $language = Language::factory()->create();

    $pageA = Page::factory()->create();
    Model::withoutEvents(
        fn (): Translation => Translation::factory()
            ->translatable($pageA)
            ->language($language)
            ->slug('shared-slug')
            ->createOne(),
    );
    PageUrl::factory()->create([
        'pageable_type' => $pageA->getMorphClass(),
        'pageable_id' => $pageA->getKey(),
        'site_id' => $pageA->site_id,
        'language_id' => $language->id,
        'url' => 'shared-slug',
    ]);
    recordRevisionFor($pageA);
    $targetVersion = resolve(RollbackService::class)->currentVersion($pageA->uuid);

    // pageA gives up the slug; pageB takes it.
    $pageA->pageUrls()->first()->forceFill(['url' => 'moved-slug'])->save();
    recordRevisionFor($pageA);

    $pageB = Page::factory()->site($pageA->site_id)->create();
    PageUrl::factory()->create([
        'pageable_type' => $pageB->getMorphClass(),
        'pageable_id' => $pageB->getKey(),
        'site_id' => $pageA->site_id,
        'language_id' => $language->id,
        'url' => 'shared-slug',
    ]);

    $preview = BuildRollbackPreviewAction::run($pageA->fresh(), $targetVersion);

    expect($preview->isBlocked())->toBeTrue();
    expect($preview->blockingIssues()[0]->code)->toBe('page_url_conflict');
});

it('projects a publish onto the workflow read model and visible_from', function (): void {
    $page = Page::factory()->create(['visible_from' => null]);

    PageAggregate::retrieve($page->uuid)->publishNow()->persist();

    $state = PageWorkflowState::query()->where('page_uuid', $page->uuid)->firstOrFail();
    expect($state->status)->toBe(PageWorkflowStatus::Published);
    expect($page->fresh()->visible_from)->not->toBeNull();
});
