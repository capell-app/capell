<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\SavePageAuthoringAction;
use Capell\Admin\Actions\Publishing\PublishRecordAction;
use Capell\Admin\Data\Pages\PageAuthoringInputData;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;

uses(CreatesAdminUser::class);

it('restores the original published revision without returning the page to draft', function (): void {
    CarbonImmutable::setTestNow('2026-08-26 12:00:00');

    $actor = test()->actingAsAdmin()->authenticatedUser();
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->createOne([
        'name' => 'CAP-0266 Golden Path v1',
        'visible_from' => PagePublishSentinel::draftValue(),
    ]);
    $translation = Translation::factory()
        ->translatable($page)
        ->language($language)
        ->slug('cap-0266-golden-path-v1')
        ->createOne([
            'title' => 'CAP-0266 Golden Path v1',
            'content' => '<p>original body</p>',
        ]);

    SavePageAuthoringAction::run(new PageAuthoringInputData(
        page: $page,
        formData: ['translations' => [['title' => 'CAP-0266 Golden Path v1']]],
    ));

    $canonicalUrl = '/cap-0266-golden-path-v1';
    $draftResolution = ResolvePublicPageByUrlAction::run($site, $language, $canonicalUrl);

    expect($draftResolution->found())->toBeFalse()
        ->and($page->pageUrls()->where('url', $canonicalUrl)->exists())->toBeTrue();

    PublishRecordAction::run($page->fresh(), $actor);

    $rollbackService = resolve(RollbackService::class);
    $revisionRows = PageRevision::query()
        ->where('page_uuid', $page->uuid)
        ->orderBy('version')
        ->get()
        ->map(fn (PageRevision $revision): array => [
            'version' => $revision->version,
            'visible_from' => data_get(
                $rollbackService->targetStateAt($page->uuid, $revision->version),
                'attributes.visible_from',
            ),
        ]);

    $initialDraftRevision = $revisionRows->firstOrFail();
    $originalPublishedRevision = $revisionRows->firstOrFail(
        static fn (array $revision): bool => is_string($revision['visible_from'])
            && ! PagePublishSentinel::isDraftValue(CarbonImmutable::parse($revision['visible_from'])),
    );
    $publishedResolution = ResolvePublicPageByUrlAction::run($site, $language, $canonicalUrl);

    expect($initialDraftRevision)->toBe([
        'version' => 1,
        'visible_from' => '2126-08-26T12:00:00+00:00',
    ])->and($originalPublishedRevision)->toBe([
        'version' => 2,
        'visible_from' => '2026-08-26T12:00:00+00:00',
    ])->and($publishedResolution->found())->toBeTrue()
        ->and($publishedResolution->fields->title)->toBe('CAP-0266 Golden Path v1');

    $translation->forceFill(['title' => 'CAP-0266 Golden Path v2'])->save();
    SavePageAuthoringAction::run(new PageAuthoringInputData(
        page: $page->fresh(),
        formData: ['translations' => [['title' => 'CAP-0266 Golden Path v2']]],
    ));

    ApplyRollbackAction::run($page->fresh(), $originalPublishedRevision['version']);

    $restoredResolution = ResolvePublicPageByUrlAction::run($site, $language, $canonicalUrl);

    expect($restoredResolution->found())->toBeTrue()
        ->and($restoredResolution->fields->title)->toBe('CAP-0266 Golden Path v1');
});
