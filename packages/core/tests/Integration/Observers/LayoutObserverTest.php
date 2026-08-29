<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\LayoutContentSnapshot;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Support\Facades\Cache;

it('flushes layout-related caches on save/delete/restore', function (): void {
    $layout = Layout::factory()->createOne();

    Cache::driver('array')->forever(CacheEnum::RelationExists->value . '-' . Layout::class . '-' . $layout->id . '-pages', true);

    $layout->name = 'Updated';
    $layout->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::RelationExists->value . '-' . Layout::class . '-' . $layout->id . '-pages');
});

it('invalidates the normalized default-model cache when a default changes', function (): void {
    $first = Layout::factory()->createOne(['default' => true]);

    expect(Layout::getDefault()?->is($first))->toBeTrue();

    $first->update(['default' => false]);
    $second = Layout::factory()->createOne(['default' => true]);

    resolve(CapellCacheManager::class)->flushLocalCache();

    expect(Layout::getDefault()?->is($second))->toBeTrue();
});

it('captures layout content before soft deletion', function (): void {
    $layout = Layout::factory()->createOne([
        'admin' => ['note' => 'Editor context'],
        'containers' => [
            'main' => [
                ['type' => 'hero', 'data' => ['heading' => 'Original hero']],
            ],
        ],
        'elements' => ['main'],
        'meta' => ['purpose' => 'Landing page'],
    ]);

    $layout->delete();

    $snapshot = LayoutContentSnapshot::query()
        ->where('layout_id', $layout->getKey())
        ->sole();

    expect($snapshot->reason)->toBe('layout_delete')
        ->and($snapshot->containers_before)->toBe($layout->getRawOriginal('containers'))
        ->and($snapshot->admin_before)->toBe($layout->getRawOriginal('admin'))
        ->and($snapshot->meta_before)->toBe($layout->getRawOriginal('meta'))
        ->and($snapshot->elements_before)->toBe($layout->getRawOriginal('elements'));
});

it('does not capture layout content during force deletion', function (): void {
    $layout = Layout::factory()->createOne([
        'containers' => [
            'main' => [
                ['type' => 'content', 'data' => ['content' => 'Original body']],
            ],
        ],
    ]);

    $layout->forceDelete();

    expect(LayoutContentSnapshot::query()->where('layout_id', $layout->getKey())->count())->toBe(0);
});

it('rebuilds layout content graph edges after a layout is saved', function (): void {
    $theme = Theme::factory()->createOne();
    $layout = Layout::factory()->createOne();

    defer()->invoke();
    ContentGraphEdge::query()->delete();

    $layout->update(['theme_id' => $theme->getKey()]);

    expect(ContentGraphEdge::query()->where([
        'source_type' => Layout::class,
        'source_id' => $layout->getKey(),
        'target_type' => Theme::class,
        'target_id' => $theme->getKey(),
        'kind' => ContentGraphEdgeKind::UsesTheme,
    ])->exists())->toBeFalse();

    defer()->invoke();

    expect(ContentGraphEdge::query()->where([
        'source_type' => Layout::class,
        'source_id' => $layout->getKey(),
        'target_type' => Theme::class,
        'target_id' => $theme->getKey(),
        'kind' => ContentGraphEdgeKind::UsesTheme,
    ])->exists())->toBeTrue();
});

it('prunes layout content graph edges after a layout is deleted', function (): void {
    $layout = Layout::factory()->createOne();

    ContentGraphEdge::query()->create([
        'source_type' => Layout::class,
        'source_id' => $layout->getKey(),
        'target_type' => Theme::class,
        'target_id' => Theme::factory()->createOne()->getKey(),
        'kind' => ContentGraphEdgeKind::UsesTheme,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
    ]);

    $layout->delete();

    expect(ContentGraphEdge::query()
        ->where('source_type', Layout::class)
        ->where('source_id', $layout->getKey())
        ->exists())->toBeFalse();
});
