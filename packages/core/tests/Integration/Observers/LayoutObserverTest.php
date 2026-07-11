<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Models\LayoutContentSnapshot;
use Illuminate\Support\Facades\Cache;

it('flushes layout-related caches on save/delete/restore', function (): void {
    $layout = Layout::factory()->createOne();

    Cache::driver('array')->forever(CacheEnum::RelationExists->value . '-' . Layout::class . '-' . $layout->id . '-pages', true);

    $layout->name = 'Updated';
    $layout->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::RelationExists->value . '-' . Layout::class . '-' . $layout->id . '-pages');
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
