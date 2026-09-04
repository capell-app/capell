<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;

it('groups dependents by model type and reports delete safety', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language, siteDomainData: [
            'domain' => 'example.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->createOne();
    $page = Page::factory()->site($site)->createOne(['name' => 'Shared landing']);
    $layout = Layout::factory()->site($site)->createOne();

    PageUrl::factory()->page($page)->site($site)->language($language)->createOne(['url' => '/landing']);

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
    ]);

    $preview = BuildContentImpactPreviewAction::run($layout);

    expect($preview->blocked)->toBeTrue()
        ->and($preview->groups)->toHaveCount(1)
        ->and($preview->groups[0]->label)->toBe('Pages')
        ->and($preview->groups[0]->count)->toBe(1)
        ->and($preview->groups[0]->dependencies[0]->name)->toBe('Shared landing')
        ->and($preview->groups[0]->dependencies[0]->type)->toBe('Page')
        ->and($preview->groups[0]->dependencies[0]->site)->toBe($site->name)
        ->and($preview->groups[0]->dependencies[0]->locales)->toBe(['en'])
        ->and($preview->groups[0]->dependencies[0]->urls[0]->url)->toBe('http://example.test/landing')
        ->and($preview->groups[0]->dependencies[0]->consequence)
        ->toBe(__('capell-core::generic.content_impact_consequence_strong'));
});

it('returns an empty preview when the target has no dependents', function (): void {
    $layout = Layout::factory()->createOne();

    $preview = BuildContentImpactPreviewAction::run($layout);

    expect($preview->blocked)->toBeFalse()
        ->and($preview->strongCount)->toBe(0)
        ->and($preview->weakCount)->toBe(0)
        ->and($preview->informationalCount)->toBe(0)
        ->and($preview->groups)->toBe([]);
});

it('counts multiple edges from the same source record once by strongest strength', function (): void {
    $strongPage = Page::factory()->createOne();
    $weakPage = Page::factory()->createOne();
    $layout = Layout::factory()->createOne();

    createContentImpactPreviewEdge($strongPage, $layout, ContentGraphEdgeStrength::Weak, ContentGraphEdgeKind::UsesLayout);
    createContentImpactPreviewEdge($strongPage, $layout, ContentGraphEdgeStrength::Strong, ContentGraphEdgeKind::UsesTheme);
    createContentImpactPreviewEdge($weakPage, $layout, ContentGraphEdgeStrength::Weak, ContentGraphEdgeKind::UsesLayout);
    createContentImpactPreviewEdge($weakPage, $layout, ContentGraphEdgeStrength::Weak, ContentGraphEdgeKind::UsesTheme);

    $preview = BuildContentImpactPreviewAction::run($layout);

    expect($preview->blocked)->toBeTrue()
        ->and($preview->strongCount)->toBe(1)
        ->and($preview->weakCount)->toBe(1)
        ->and($preview->informationalCount)->toBe(0)
        ->and($preview->groups)->toHaveCount(1)
        ->and($preview->groups[0]->strongestStrength)->toBe(ContentGraphEdgeStrength::Strong)
        ->and($preview->groups[0]->count)->toBe(2)
        ->and(collect($preview->groups[0]->dependencies)->pluck('name')->sort()->values()->all())
        ->toBe(collect([$strongPage->name, $weakPage->name])->sort()->values()->all());
});

it('counts weak and informational source records correctly', function (): void {
    $weakPage = Page::factory()->createOne();
    $informationalPage = Page::factory()->createOne();
    $layout = Layout::factory()->createOne();

    createContentImpactPreviewEdge($weakPage, $layout, ContentGraphEdgeStrength::Weak);
    createContentImpactPreviewEdge($informationalPage, $layout, ContentGraphEdgeStrength::Informational, ContentGraphEdgeKind::UsesLayout);
    createContentImpactPreviewEdge($informationalPage, $layout, ContentGraphEdgeStrength::Informational, ContentGraphEdgeKind::UsesTheme);

    $preview = BuildContentImpactPreviewAction::run($layout);

    expect($preview->blocked)->toBeFalse()
        ->and($preview->strongCount)->toBe(0)
        ->and($preview->weakCount)->toBe(1)
        ->and($preview->informationalCount)->toBe(1)
        ->and($preview->groups)->toHaveCount(1)
        ->and($preview->groups[0]->strongestStrength)->toBe(ContentGraphEdgeStrength::Weak)
        ->and($preview->groups[0]->count)->toBe(2);
});

it('groups multiple source models with editor-facing labels and dependencies', function (): void {
    $page = Page::factory()->createOne();
    $firstSite = Site::factory()->createOne();
    $secondSite = Site::factory()->createOne();
    $layout = Layout::factory()->createOne();

    createContentImpactPreviewEdge($page, $layout, ContentGraphEdgeStrength::Informational);
    createContentImpactPreviewEdge($firstSite, $layout, ContentGraphEdgeStrength::Weak, ContentGraphEdgeKind::UsesLayout);
    createContentImpactPreviewEdge($firstSite, $layout, ContentGraphEdgeStrength::Informational, ContentGraphEdgeKind::UsesTheme);
    createContentImpactPreviewEdge($secondSite, $layout, ContentGraphEdgeStrength::Informational);

    $preview = BuildContentImpactPreviewAction::run($layout);
    $groups = collect($preview->groups)->keyBy('label');

    expect($preview->blocked)->toBeFalse()
        ->and($preview->strongCount)->toBe(0)
        ->and($preview->weakCount)->toBe(1)
        ->and($preview->informationalCount)->toBe(2)
        ->and($groups)->toHaveCount(2)
        ->and($groups['Pages']->strongestStrength)->toBe(ContentGraphEdgeStrength::Informational)
        ->and($groups['Pages']->count)->toBe(1)
        ->and($groups['Pages']->dependencies[0]->name)->toBe($page->name)
        ->and($groups['Sites']->label)->toBe('Sites')
        ->and($groups['Sites']->strongestStrength)->toBe(ContentGraphEdgeStrength::Weak)
        ->and($groups['Sites']->count)->toBe(2)
        ->and(collect($groups['Sites']->dependencies)->pluck('name')->sort()->values()->all())
        ->toBe(collect([$firstSite->name, $secondSite->name])->sort()->values()->all());
});

it('filters inaccessible dependent models before building the preview', function (): void {
    $visiblePage = Page::factory()->createOne(['name' => 'Visible page']);
    $hiddenPage = Page::factory()->createOne(['name' => 'Hidden page']);
    $layout = Layout::factory()->createOne();

    createContentImpactPreviewEdge($visiblePage, $layout, ContentGraphEdgeStrength::Strong);
    createContentImpactPreviewEdge($hiddenPage, $layout, ContentGraphEdgeStrength::Strong);

    $preview = BuildContentImpactPreviewAction::run(
        $layout,
        fn (Page $page): bool => $page->name === 'Visible page',
    );

    expect($preview->strongCount)->toBe(1)
        ->and($preview->groups[0]->count)->toBe(1)
        ->and($preview->groups[0]->dependencies[0]->name)->toBe('Visible page');
});

function createContentImpactPreviewEdge(
    Page|Site $source,
    Layout $layout,
    ContentGraphEdgeStrength $strength,
    ContentGraphEdgeKind $kind = ContentGraphEdgeKind::UsesLayout,
): void {
    ContentGraphEdge::query()->create([
        'source_type' => $source::class,
        'source_id' => $source->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => $kind,
        'strength' => $strength,
        'source_package' => 'capell-app/core',
    ]);
}
