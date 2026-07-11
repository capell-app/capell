<?php

declare(strict_types=1);

use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;

it('hydrates a graph edge from source and target nodes', function (): void {
    $edge = new ContentGraphEdgeData(
        source: ContentGraphNodeData::fromModelIdentity(Page::class, 10),
        target: ContentGraphNodeData::fromModelIdentity(Layout::class, 20),
        kind: ContentGraphEdgeKind::UsesLayout,
        strength: ContentGraphEdgeStrength::Strong,
        sourcePackage: 'capell-app/core',
        siteId: 1,
        languageId: 2,
        metadata: ['reason' => 'page layout_id'],
    );

    expect($edge->source->modelType)->toBe(Page::class)
        ->and($edge->source->modelId)->toBe(10)
        ->and($edge->target->modelType)->toBe(Layout::class)
        ->and($edge->target->modelId)->toBe(20)
        ->and($edge->kind)->toBe(ContentGraphEdgeKind::UsesLayout)
        ->and($edge->strength)->toBe(ContentGraphEdgeStrength::Strong)
        ->and($edge->sourcePackage)->toBe('capell-app/core')
        ->and($edge->metadata)->toBe(['reason' => 'page layout_id']);
});

it('keeps graph edge collections typed', function (): void {
    $collection = ContentGraphEdgeCollectionData::make([
        new ContentGraphEdgeData(
            source: ContentGraphNodeData::fromModelIdentity(Page::class, 10),
            target: ContentGraphNodeData::fromModelIdentity(Layout::class, 20),
            kind: ContentGraphEdgeKind::UsesLayout,
            strength: ContentGraphEdgeStrength::Strong,
            sourcePackage: 'capell-app/core',
        ),
    ]);

    expect($collection->edges)->toHaveCount(1)
        ->and($collection->edges[0])->toBeInstanceOf(ContentGraphEdgeData::class);
});
