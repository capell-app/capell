<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentGraph\BuildContentGraphForModelAction;
use Capell\Core\Actions\ContentGraph\FindContentGraphDependentsAction;
use Capell\Core\Actions\ContentGraph\PruneContentGraphEdgesAction;
use Capell\Core\Actions\ContentGraph\RebuildContentGraphForModelAction;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Tests\Support\Fixtures\Autoload\ActionTestPageGraphExtractor;
use Capell\Core\Tests\Support\Fixtures\Autoload\DuplicateActionTestPageGraphExtractor;
use Capell\Core\Tests\Support\Fixtures\Autoload\StringKindActionTestPageGraphExtractor;

it('builds graph edges for a model without writing them', function (): void {
    resolve(ContentGraphRegistry::class)->register(ActionTestPageGraphExtractor::class);
    $page = Page::factory()->createOne();

    $edges = BuildContentGraphForModelAction::run($page);

    expect(collect($edges->edges)
        ->contains(fn (ContentGraphEdgeData $edge): bool => $edge->target->modelType === Layout::class && $edge->target->modelId === 42))->toBeTrue()
        ->and(ContentGraphEdge::query()->count())->toBe(0);
});

it('rebuilds graph edges idempotently for a source model', function (): void {
    resolve(ContentGraphRegistry::class)->register(ActionTestPageGraphExtractor::class);
    $page = Page::factory()->createOne();

    RebuildContentGraphForModelAction::run($page);
    RebuildContentGraphForModelAction::run($page);

    expect(ContentGraphEdge::query()
        ->where('target_type', Layout::class)
        ->where('target_id', 42)
        ->count())->toBe(1);
});

it('rebuilds package-defined string graph edge kinds', function (): void {
    resolve(ContentGraphRegistry::class)->register(StringKindActionTestPageGraphExtractor::class);
    $page = Page::factory()->createOne();

    RebuildContentGraphForModelAction::run($page);

    $edge = ContentGraphEdge::query()
        ->where('target_type', Layout::class)
        ->where('target_id', 84)
        ->firstOrFail();

    expect($edge->kind)->toBe('vendor_uses_renderable')
        ->and($edge->source_package)->toBe('vendor/package');
});

it('rebuilds graph edges without failing on duplicate extracted edges', function (): void {
    resolve(ContentGraphRegistry::class)->register(DuplicateActionTestPageGraphExtractor::class);
    $page = Page::factory()->createOne();

    RebuildContentGraphForModelAction::run($page);

    expect(ContentGraphEdge::query()
        ->where('target_type', Layout::class)
        ->where('target_id', 42)
        ->count())->toBe(1);
});

it('finds dependents by target model identity', function (): void {
    resolve(ContentGraphRegistry::class)->register(ActionTestPageGraphExtractor::class);
    $page = Page::factory()->createOne();
    RebuildContentGraphForModelAction::run($page);

    $dependents = FindContentGraphDependentsAction::run(Layout::class, 42);

    expect($dependents)->toHaveCount(1)
        ->and($dependents->first()->source_type)->toBe(Page::class);
});

it('prunes edges for a source model', function (): void {
    resolve(ContentGraphRegistry::class)->register(ActionTestPageGraphExtractor::class);
    $page = Page::factory()->createOne();
    RebuildContentGraphForModelAction::run($page);

    PruneContentGraphEdgesAction::run($page);

    expect(ContentGraphEdge::query()->count())->toBe(0);
});
