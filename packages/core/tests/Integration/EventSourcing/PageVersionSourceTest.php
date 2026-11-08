<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Actions\RecordPageVersionAction;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackPreviewData;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Support\PageVersionContext;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;

function pageWithFirstVersion(string $title = 'First'): Page
{
    $page = Page::factory()->createOne();
    Translation::factory()->translatable($page)->language(Language::factory()->createOne())
        ->create(['title' => $title, 'content' => '<p>' . $title . '</p>']);
    $page->load(['translations', 'pageUrls']);
    $page->save();

    return $page;
}

function latestRevision(Page $page): PageRevision
{
    return PageRevision::query()->where('page_uuid', $page->uuid)->orderByDesc('version')->firstOrFail();
}

it('records an ordinary save as an edit', function (): void {
    $page = pageWithFirstVersion();

    expect(latestRevision($page)->source)->toBe(PageVersionSource::Edit->value)
        ->and(latestRevision($page)->versionSource())->toBe(PageVersionSource::Edit);
});

it('records a package publish as a labelled version that the rollback engine can restore', function (): void {
    $page = pageWithFirstVersion();
    $translation = $page->translations()->firstOrFail();
    $translation->forceFill(['title' => 'Published title'])->save();

    $version = RecordPageVersionAction::run($page->fresh(['translations', 'pageUrls']), PageVersionSource::Publish, 'Spring campaign');

    $revision = latestRevision($page);

    expect($revision->version)->toBe($version)
        ->and($revision->versionSource())->toBe(PageVersionSource::Publish)
        ->and($revision->label)->toBe('Spring campaign')
        ->and($version)->toBe(resolve(RollbackService::class)->currentVersion($page->uuid));

    // The provenance does not leak into the next save.
    $page->load(['translations', 'pageUrls']);
    $page->save();

    expect(latestRevision($page)->versionSource())->toBe(PageVersionSource::Edit)
        ->and(resolve(PageVersionContext::class)->source())->toBeNull();
});

it('refuses to record a rollback outside the rollback engine', function (): void {
    RecordPageVersionAction::run(pageWithFirstVersion(), PageVersionSource::Rollback);
})->throws(InvalidArgumentException::class);

it('keeps a rollback labelled as a rollback even inside an import', function (): void {
    $page = pageWithFirstVersion();
    $first = resolve(RollbackService::class)->currentVersion($page->uuid);
    $page->translations()->firstOrFail()->forceFill(['title' => 'Second'])->save();
    $page->load(['translations', 'pageUrls']);
    $page->save();

    resolve(PageVersionContext::class)->run(
        PageVersionSource::Import,
        null,
        fn (): RollbackPreviewData => ApplyRollbackAction::run($page->fresh(), $first),
    );

    expect(latestRevision($page)->versionSource())->toBe(PageVersionSource::Rollback)
        ->and(latestRevision($page)->is_rollback)->toBeTrue();
});

it('reads versions indexed before provenance existed as edits and rollbacks', function (): void {
    $page = pageWithFirstVersion();
    DB::table((new PageRevision)->getTable())->where('page_uuid', $page->uuid)->update(['source' => null]);

    $legacy = latestRevision($page);
    $legacyRollback = (new PageRevision)->forceFill(['source' => null, 'is_rollback' => true]);

    expect($legacy->versionSource())->toBe(PageVersionSource::Edit)
        ->and($legacyRollback->versionSource())->toBe(PageVersionSource::Rollback);
});
