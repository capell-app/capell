<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintSchemaSnapshot;
use Illuminate\Support\Facades\Cache;

it('flushes blueprint-related caches on save/delete/restore', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne();

    Cache::driver('array')->forever(CacheEnum::ModelDefaultExists->value . '-' . Blueprint::class . '-page', true);

    $blueprint->name = 'Updated';
    $blueprint->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::ModelDefaultExists->value . '-' . Blueprint::class . '-page');
});

it('captures blueprint schema before schema metadata changes', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne([
        'admin' => [
            'configurator' => 'Default',
            'type_configurator' => 'Page',
        ],
        'meta' => [
            'content_structure' => 'advanced',
            'cache_time' => 'hour',
        ],
    ]);

    $adminBefore = $blueprint->getRawOriginal('admin');
    $metaBefore = $blueprint->getRawOriginal('meta');
    $typeBefore = $blueprint->getRawOriginal('type');

    $blueprint->update([
        'admin' => [
            'configurator' => 'LandingPage',
            'type_configurator' => 'Page',
        ],
        'meta' => [
            'content_structure' => 'layout_builder',
            'cache_time' => 'hour',
        ],
    ]);

    $snapshot = BlueprintSchemaSnapshot::query()
        ->where('blueprint_id', $blueprint->getKey())
        ->sole();

    expect($snapshot->reason)->toBe('blueprint_schema_update')
        ->and($snapshot->blueprint_key)->toBe($blueprint->key)
        ->and($snapshot->blueprint_type)->toBe(BlueprintSubjectEnum::Page->value)
        ->and($snapshot->admin_before)->toBe($adminBefore)
        ->and($snapshot->meta_before)->toBe($metaBefore)
        ->and($snapshot->type_before)->toBe($typeBefore)
        ->and($snapshot->metadata)->toBe(['changed' => ['admin', 'meta']]);
});

it('does not capture blueprint schema for non-schema updates', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne();

    $blueprint->update(['name' => 'Renamed type']);

    expect(BlueprintSchemaSnapshot::query()->where('blueprint_id', $blueprint->getKey())->count())->toBe(0);
});

it('does not capture blueprint schema when only role restrictions are cleaned up', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne([
        'admin' => [
            'configurator' => 'Default',
        ],
    ]);

    $blueprint->update([
        'admin' => [
            'configurator' => 'Default',
            'role_restrictions' => [],
        ],
    ]);

    expect(BlueprintSchemaSnapshot::query()->where('blueprint_id', $blueprint->getKey())->count())->toBe(0);
});
