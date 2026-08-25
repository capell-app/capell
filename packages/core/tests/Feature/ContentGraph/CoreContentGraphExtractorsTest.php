<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentGraph\BuildContentGraphForModelAction;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;

it('extracts page content graph dependencies', function (): void {
    $site = Site::factory()->createOne();
    $layout = Layout::factory()->site($site)->create();
    $canonicalPage = Page::factory()->site($site)->layout($layout)->create();
    $relatedPage = Page::factory()->site($site)->layout($layout)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->canonicalPage($canonicalPage)
        ->create([
            'meta' => [
                'canonical_pageable_type' => $canonicalPage->getMorphClass(),
                'canonical_pageable_id' => $canonicalPage->getKey(),
                'related' => [$relatedPage->id],
            ],
        ]);

    $edges = BuildContentGraphForModelAction::run($page)->edges;

    expect(expectEdge($edges, ContentGraphEdgeKind::UsesLayout, Layout::class, $layout->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::BelongsToSite, Site::class, $site->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::CanonicalizesTo, Page::class, $canonicalPage->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::RelatesToPage, Page::class, $relatedPage->id, ContentGraphEdgeStrength::Weak))->toBeTrue();
});

it('extracts page media dependencies and skips malformed page references', function (): void {
    $page = Page::factory()->createOne([
        'meta' => [
            'canonical_pageable_type' => 'missing-model-alias',
            'canonical_pageable_id' => 'not-numeric',
            'related' => ['not-numeric', null, []],
        ],
    ]);
    $image = Media::factory()->model($page)->collection(MediaCollectionEnum::Image)->create();
    $socialImage = Media::factory()->model($page)->collection(MediaCollectionEnum::SocialImage)->create();

    $edges = BuildContentGraphForModelAction::run($page)->edges;

    expect(expectEdge($edges, ContentGraphEdgeKind::UsesMedia, Media::class, $image->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::UsesMedia, Media::class, $socialImage->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(hasEdgeKind($edges, ContentGraphEdgeKind::CanonicalizesTo))->toBeFalse()
        ->and(hasEdgeKind($edges, ContentGraphEdgeKind::RelatesToPage))->toBeFalse();
});

it('extracts layout content graph dependencies', function (): void {
    $site = Site::factory()->createOne();
    $theme = Theme::factory()->createOne();
    $layout = Layout::factory()
        ->site($site)
        ->create([
            'theme_id' => $theme->id,
        ]);
    $image = Media::factory()->model($layout)->collection(MediaCollectionEnum::Image)->create();

    $edges = BuildContentGraphForModelAction::run($layout)->edges;

    expect(expectEdge($edges, ContentGraphEdgeKind::BelongsToSite, Site::class, $site->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::UsesTheme, Theme::class, $theme->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::UsesMedia, Media::class, $image->id, ContentGraphEdgeStrength::Strong))->toBeTrue();
});

it('extracts site language theme media and related site dependencies', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->createOne();
    $relatedSite = Site::factory()->createOne();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->create([
            'meta' => [
                'related' => [$relatedSite->id],
            ],
        ]);
    $logo = Media::factory()->model($site)->collection(MediaCollectionEnum::Logo)->create();

    $edges = BuildContentGraphForModelAction::run($site)->edges;

    expect(expectEdge($edges, ContentGraphEdgeKind::BelongsToLanguage, Language::class, $language->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::UsesTheme, Theme::class, $theme->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::UsesMedia, Media::class, $logo->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::RelatesToPage, Site::class, $relatedSite->id, ContentGraphEdgeStrength::Weak))->toBeTrue();
});

it('extracts page URL content graph dependencies', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->create();
    $page = Page::factory()->site($site)->create();
    $pageUrl = PageUrl::factory()
        ->page($page)
        ->create([
            'language_id' => $language->id,
            'site_id' => $site->id,
        ]);

    $edges = BuildContentGraphForModelAction::run($pageUrl)->edges;

    expect(expectEdge($edges, ContentGraphEdgeKind::ResolvesToPage, Page::class, $page->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::BelongsToSite, Site::class, $site->id, ContentGraphEdgeStrength::Strong))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::BelongsToLanguage, Language::class, $language->id, ContentGraphEdgeStrength::Strong))->toBeTrue();
});

it('extracts found on page edges for pages embedded in composed block content', function (): void {
    $site = Site::factory()->createOne();
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $featurePage = Page::factory()->site($site)->create();
    $actionPage = Page::factory()->site($site)->create();
    $listedPage = Page::factory()->site($site)->create();
    $nestedPage = Page::factory()->site($site)->create();
    $page = Page::factory()->site($site)->type($blueprint)->create();

    Translation::factory()
        ->translatable($page)
        ->create([
            'content' => [
                [
                    'type' => 'feature',
                    'data' => ['page_id' => $featurePage->id],
                ],
                [
                    'type' => 'cta-section',
                    'data' => [
                        'actions' => [
                            ['type' => 'page', 'pageable_id' => (string) $actionPage->id],
                        ],
                    ],
                ],
                [
                    'type' => 'listing',
                    'data' => ['page_ids' => [$listedPage->id, $page->id]],
                ],
                [
                    'type' => 'columns',
                    'widgets' => [
                        [
                            'type' => 'card-grid',
                            'data' => ['items' => [['pages' => [$nestedPage->id, $featurePage->id]]]],
                        ],
                    ],
                ],
            ],
        ]);

    Translation::factory()
        ->translatable($page)
        ->create([
            'content' => [
                [
                    'type' => 'feature',
                    'data' => ['page_id' => $featurePage->id],
                ],
            ],
        ]);

    $edges = BuildContentGraphForModelAction::run($page)->edges;
    $foundOnPageTargets = collect($edges)
        ->filter(fn (ContentGraphEdgeData $edge): bool => $edge->kind === ContentGraphEdgeKind::FoundOnPage)
        ->map(fn (ContentGraphEdgeData $edge): int => $edge->target->modelId)
        ->sort()
        ->values()
        ->all();

    expect(expectEdge($edges, ContentGraphEdgeKind::FoundOnPage, Page::class, $featurePage->id, ContentGraphEdgeStrength::Weak))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::FoundOnPage, Page::class, $actionPage->id, ContentGraphEdgeStrength::Weak))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::FoundOnPage, Page::class, $listedPage->id, ContentGraphEdgeStrength::Weak))->toBeTrue()
        ->and(expectEdge($edges, ContentGraphEdgeKind::FoundOnPage, Page::class, $nestedPage->id, ContentGraphEdgeStrength::Weak))->toBeTrue()
        ->and($foundOnPageTargets)->toBe([$featurePage->id, $actionPage->id, $listedPage->id, $nestedPage->id]);
});

it('skips malformed self and non page embedded references in composed content', function (): void {
    $site = Site::factory()->createOne();
    $blueprint = Blueprint::factory()->contentStructure(ContentStructure::Blocks)->createOne();
    $page = Page::factory()->site($site)->type($blueprint)->create();

    Translation::factory()
        ->translatable($page)
        ->create([
            'content' => [
                [
                    'type' => 'feature',
                    'data' => ['page_id' => 'not-numeric'],
                ],
                [
                    'type' => 'feature',
                    'data' => ['page_id' => $page->id],
                ],
                [
                    'type' => 'cta-section',
                    'data' => [
                        'actions' => [
                            [
                                'type' => 'page',
                                'pageable_id' => $site->id,
                                'pageable_type' => $site->getMorphClass(),
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'widget',
                    'data' => ['__capell' => ['page_id' => 999_999]],
                ],
                [
                    'type' => 'listing',
                    'data' => ['page_ids' => ['not-numeric', null, [], 0, -3]],
                ],
            ],
        ]);

    $edges = BuildContentGraphForModelAction::run($page)->edges;

    expect(hasEdgeKind($edges, ContentGraphEdgeKind::FoundOnPage))->toBeFalse();
});

it('does not extract found on page edges from html structured content', function (): void {
    $page = Page::factory()->createOne();

    Translation::factory()
        ->translatable($page)
        ->create([
            'content' => '<p>{"page_id": 12, "page_ids": [34]}</p>',
        ]);

    $edges = BuildContentGraphForModelAction::run($page)->edges;

    expect(hasEdgeKind($edges, ContentGraphEdgeKind::FoundOnPage))->toBeFalse();
});

it('extracts media owner dependencies', function (): void {
    $page = Page::factory()->createOne();
    $media = Media::factory()->model($page)->create();

    $edges = BuildContentGraphForModelAction::run($media)->edges;

    expect(expectEdge($edges, ContentGraphEdgeKind::UsesMedia, Page::class, $page->id, ContentGraphEdgeStrength::Informational))->toBeTrue();
});

/**
 * @param  array<int, ContentGraphEdgeData>  $edges
 * @param  class-string<Model>  $targetType
 */
function expectEdge(
    array $edges,
    ContentGraphEdgeKind $kind,
    string $targetType,
    int $targetId,
    ContentGraphEdgeStrength $strength,
): bool {
    return collect($edges)->contains(
        fn (ContentGraphEdgeData $edge): bool => $edge->kind === $kind
            && $edge->target->modelType === $targetType
            && $edge->target->modelId === $targetId
            && $edge->strength === $strength,
    );
}

/**
 * @param  array<int, ContentGraphEdgeData>  $edges
 */
function hasEdgeKind(array $edges, ContentGraphEdgeKind $kind): bool
{
    return collect($edges)->contains(
        fn (ContentGraphEdgeData $edge): bool => $edge->kind === $kind,
    );
}
