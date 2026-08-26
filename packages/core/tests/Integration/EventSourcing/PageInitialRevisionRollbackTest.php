<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('rolls back to the first complete authoring revision without wiping its unchanged canonical url', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->createOne([
        'name' => 'CAP-0266 Golden Path v1',
        'visible_from' => null,
    ]);
    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->slug('cap-0266-golden-path-v1')
        ->createOne([
            'title' => 'CAP-0266 Golden Path v1',
            'content' => '<p>original body</p>',
        ]);

    $page->load(['translations', 'pageUrls']);
    $page->save();
    PageAggregate::retrieve($page->uuid)->publishNow()->persist();

    $translation->forceFill(['title' => 'CAP-0266 Golden Path v2'])->save();
    $page->load(['translations', 'pageUrls']);
    $page->save();

    $targetVersion = PageRevision::query()
        ->where('page_uuid', $page->uuid)
        ->orderBy('version')
        ->firstOrFail()
        ->version;
    $canonicalUrl = '/cap-0266-golden-path-v1';
    $publishedResolution = ResolvePublicPageByUrlAction::run($site, $language, $canonicalUrl);

    expect($targetVersion)->toBe(1)
        ->and(resolve(RollbackService::class)->currentVersion($page->uuid))->toBeGreaterThan($targetVersion)
        ->and($page->pageUrls()->where('url', $canonicalUrl)->exists())->toBeTrue()
        ->and($publishedResolution->found())->toBeTrue()
        ->and($publishedResolution->fields->title)->toBe('CAP-0266 Golden Path v2');

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    $restoredResolution = ResolvePublicPageByUrlAction::run($site, $language, $canonicalUrl);

    expect($page->pageUrls()->where('url', $canonicalUrl)->exists())->toBeTrue()
        ->and($restoredResolution->found())->toBeTrue()
        ->and($restoredResolution->page?->getKey())->toBe($page->getKey())
        ->and($restoredResolution->fields->url)->toBe($canonicalUrl)
        ->and($restoredResolution->fields->title)->toBe('CAP-0266 Golden Path v1');
});
