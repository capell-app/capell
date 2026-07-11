<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the content graph edge table with queryable source and target columns', function (): void {
    expect(Schema::hasTable('content_graph_edges'))->toBeTrue()
        ->and(Schema::hasColumns('content_graph_edges', [
            'id',
            'source_type',
            'source_id',
            'target_type',
            'target_id',
            'kind',
            'strength',
            'source_package',
            'site_id',
            'language_id',
            'metadata',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('stores nullable scoped graph edges and enforces unique edge identity', function (): void {
    $edge = ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => 10,
        'target_type' => Layout::class,
        'target_id' => 20,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
        'site_id' => null,
        'language_id' => null,
        'metadata' => ['reason' => 'page layout_id'],
    ]);

    $rehydratedEdge = ContentGraphEdge::query()->findOrFail($edge->id);

    expect($rehydratedEdge->site_id)->toBeNull()
        ->and($rehydratedEdge->language_id)->toBeNull()
        ->and($rehydratedEdge->kind)->toBe(ContentGraphEdgeKind::UsesLayout->value)
        ->and($rehydratedEdge->strength)->toBe(ContentGraphEdgeStrength::Strong)
        ->and($rehydratedEdge->metadata)->toBe(['reason' => 'page layout_id']);

    expect(fn (): ContentGraphEdge => ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => 10,
        'target_type' => Layout::class,
        'target_id' => 20,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Weak,
        'source_package' => 'capell-app/core',
        'site_id' => null,
        'language_id' => null,
    ]))->toThrow(QueryException::class);
});

it('stores package-defined graph edge kinds as plain strings', function (): void {
    $edge = ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => 30,
        'target_type' => Layout::class,
        'target_id' => 40,
        'kind' => 'vendor_uses_renderable',
        'strength' => ContentGraphEdgeStrength::Informational,
        'source_package' => 'vendor/package',
        'site_id' => null,
        'language_id' => null,
        'metadata' => ['reason' => 'package-defined edge'],
    ]);

    $rehydratedEdge = ContentGraphEdge::query()->findOrFail($edge->id);

    expect($rehydratedEdge->kind)->toBe('vendor_uses_renderable')
        ->and($rehydratedEdge->strength)->toBe(ContentGraphEdgeStrength::Informational)
        ->and($rehydratedEdge->metadata)->toBe(['reason' => 'package-defined edge']);
});
