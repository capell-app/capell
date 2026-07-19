<?php

declare(strict_types=1);

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Components\ComponentRegistry;
use Illuminate\Support\Facades\File;

it('owns component registration discovery and selective operation reset', function (): void {
    $root = storage_path('framework/testing/component-registry-' . uniqid());
    File::ensureDirectoryExists($root . '/page-sections');
    File::put($root . '/page-sections/first.blade.php', '<section>First</section>');
    config(['capell.cache_path' => $root . '/cache']);

    $registry = new ComponentRegistry;
    $registry
        ->registerComponent(AssetComponentEnum::Card, AssetComponentEnum::Media, 'manual-card')
        ->registerComponent(AssetComponentEnum::Card, AssetComponentEnum::Media, 'ignored-duplicate')
        ->registerDiscoverableComponents($root, 'public');

    expect($registry->getComponent('PageSections', 'public.first'))->toBe('public.first')
        ->and($registry->getCoreComponents('Card'))->toBe(['Media' => 'manual-card'])
        ->and($registry->hasCachedComponents())->toBeFalse();

    $registry->cacheComponents();

    expect($registry->hasCachedComponents())->toBeTrue();

    File::delete($root . '/page-sections/first.blade.php');
    File::put($root . '/page-sections/second.blade.php', '<section>Second</section>');
    File::delete($registry->getComponentCachePath());

    $registry->flushOctaneState();

    expect($registry->getCoreComponents('Card'))->toBe(['Media' => 'manual-card'])
        ->and($registry->hasCachedComponents())->toBeFalse()
        ->and($registry->hasComponent('PageSections', 'public.first'))->toBeFalse()
        ->and($registry->getComponent('PageSections', 'public.second'))->toBe('public.second');

    File::deleteDirectory($root);
});

it('keeps the core manager as a method-for-method component registry adapter', function (): void {
    $manager = new CapellCoreManager;
    $registry = resolve(ComponentRegistry::class);

    $manager->registerComponent('Adapter', 'registered', 'adapter.registered');

    expect($manager->getComponents('Adapter'))->toBe(['registered' => 'adapter.registered'])
        ->and($registry->getComponents('Adapter'))->toBe(['registered' => 'adapter.registered'])
        ->and(CapellCoreManager::getComponentTypeFromDirectory('/tmp/page-sections'))->toBe('PageSections');
});
