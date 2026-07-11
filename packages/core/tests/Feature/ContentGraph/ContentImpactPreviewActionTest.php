<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

it('groups dependents by model type and reports delete safety', function (): void {
    $page = Page::factory()->createOne();
    $layout = Layout::factory()->createOne();

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
        ->and($preview->groups[0]->count)->toBe(1);
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
        ->and($preview->groups[0]->recordIds)->toBe([$strongPage->id, $weakPage->id]);
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

it('groups multiple source model blueprints with labels strongest strengths counts and record ids', function (): void {
    $page = Page::factory()->createOne();
    $firstSite = Site::factory()->createOne();
    $secondSite = Site::factory()->createOne();
    $layout = Layout::factory()->createOne();

    createContentImpactPreviewEdge($page, $layout, ContentGraphEdgeStrength::Informational);
    createContentImpactPreviewEdge($firstSite, $layout, ContentGraphEdgeStrength::Weak, ContentGraphEdgeKind::UsesLayout);
    createContentImpactPreviewEdge($firstSite, $layout, ContentGraphEdgeStrength::Informational, ContentGraphEdgeKind::UsesTheme);
    createContentImpactPreviewEdge($secondSite, $layout, ContentGraphEdgeStrength::Informational);

    $preview = BuildContentImpactPreviewAction::run($layout);
    $groups = collect($preview->groups)->keyBy('modelType');

    expect($preview->blocked)->toBeFalse()
        ->and($preview->strongCount)->toBe(0)
        ->and($preview->weakCount)->toBe(1)
        ->and($preview->informationalCount)->toBe(2)
        ->and($groups)->toHaveCount(2)
        ->and($groups[Page::class]->label)->toBe('Pages')
        ->and($groups[Page::class]->strongestStrength)->toBe(ContentGraphEdgeStrength::Informational)
        ->and($groups[Page::class]->count)->toBe(1)
        ->and($groups[Page::class]->recordIds)->toBe([$page->id])
        ->and($groups[Site::class]->label)->toBe('Sites')
        ->and($groups[Site::class]->strongestStrength)->toBe(ContentGraphEdgeStrength::Weak)
        ->and($groups[Site::class]->count)->toBe(2)
        ->and($groups[Site::class]->recordIds)->toBe([$firstSite->id, $secondSite->id]);
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
