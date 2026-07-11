<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentStructure;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\EventSourcing\Events\PageRevisionRecorded;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\Actions\BuildRollbackPreviewAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;

function recordRevisionFor(Page $page): void
{
    // Re-fire the recording bridge the way a real save would: reload relations,
    // then save so PageSaved is dispatched and a revision is captured.
    $page->load(['translations', 'pageUrls']);
    $page->save();
}

it('records a revision when a page is saved', function (): void {
    $page = Page::factory()->create();

    $revisionEvents = DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRevisionRecorded::class)
        ->count();

    expect($revisionEvents)->toBeGreaterThanOrEqual(1);
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

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    expect($translation->fresh()->title)->toBe('First');
    expect(DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRolledBack::class)
        ->count())->toBe(1);
});

it('restores the content_structure_override when rolling back across a mode switch', function (): void {
    $page = Page::factory()->create();
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
