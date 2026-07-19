<?php

declare(strict_types=1);

use Capell\Core\Data\AssetData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Octane\FlushResettableState;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Security\LockdownStore;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Laravel\Octane\Contracts\OperationTerminated;

it('flushes tagged resettable services', function (): void {
    $resettable = new class implements Resettable
    {
        public int $flushes = 0;

        public function flushOctaneState(): void
        {
            $this->flushes++;
        }
    };

    app()->instance('capell.test-resettable', $resettable);
    app()->tag(['capell.test-resettable'], Resettable::TAG);

    new FlushResettableState(app())->handle();

    expect($resettable->flushes)->toBe(1);
});

it('starts the next operation with fresh core runtime state and preserved boot registrations', function (): void {
    $componentRoot = storage_path('framework/testing/octane-components-' . uniqid());
    $cacheRoot = storage_path('framework/testing/octane-component-cache-' . uniqid());
    File::ensureDirectoryExists($componentRoot . '/widgets');
    File::put($componentRoot . '/widgets/first.blade.php', '<div>First</div>');

    config([
        'cache.default' => 'array',
        'capell.cache_path' => $cacheRoot,
        'capell.default_pages' => ['home'],
    ]);

    /** @var CapellCoreManager $manager */
    $manager = app(CapellCoreManager::class);
    /** @var CapellPackageRegistry $packages */
    $packages = app(CapellPackageRegistry::class);
    $manifestNames = array_keys($packages->all());

    $manager
        ->registerComponent('Boot', 'registered', 'boot.registered')
        ->registerDiscoverableComponents($componentRoot)
        ->registerAsset(new AssetData('OctaneAsset', Site::class))
        ->registerPageType(new PageTypeData('octane-page', Site::class))
        ->registerModels([Site::class]);
    $manager::registerModelRelations('octane-model', 'translations');

    expect($manager->getComponent('Widgets', 'first'))->toBe('first')
        ->and($manager->getModels())->toHaveKey('Site', Site::class)
        ->and($manager->getDefaultPages()->keys()->all())->toBe(['home'])
        ->and($manager->rememberCache('octane-operation', fn (): string => 'operation-one'))->toBe('operation-one');

    $manager->cacheComponents();
    $manager->getPackages();

    File::delete($componentRoot . '/widgets/first.blade.php');
    File::put($componentRoot . '/widgets/second.blade.php', '<div>Second</div>');
    File::delete($manager->getComponentCachePath());
    /** @var CapellCacheManager $cache */
    $cache = app(CapellCacheManager::class);
    $normalizeCacheKey = new ReflectionMethod($cache, 'normalizeCacheKey');
    $cacheRepository = new ReflectionMethod($cache, 'getCacheInstance');
    $cacheRepository->invoke($cache)->put(
        $normalizeCacheKey->invoke($cache, 'octane-operation'),
        'operation-two',
    );
    config(['capell.default_pages' => ['contact']]);
    $manager->registerPackage('vendor/operation-two');

    new FlushResettableState(app())->handle();

    $manager->registerModels([SiteDomain::class]);

    expect($manager->hasComponent('Widgets', 'first'))->toBeFalse()
        ->and($manager->getComponent('Widgets', 'second'))->toBe('second')
        ->and($manager->hasCachedComponents())->toBeFalse()
        ->and($manager->getModels())->toBe(['SiteDomain' => SiteDomain::class])
        ->and($manager->getDefaultPages()->keys()->all())->toBe(['contact'])
        ->and($manager->getFromCache('octane-operation'))->toBe('operation-two')
        ->and($manager->getComponents('Boot'))->toBe(['registered' => 'boot.registered'])
        ->and($manager->hasAsset('OctaneAsset'))->toBeTrue()
        ->and($manager->hasPageType('octane-page'))->toBeTrue()
        ->and($manager::getModelRelations('octane-model'))->toBe(['translations'])
        ->and($manager->getPackages()->keys()->all())->toContain('vendor/operation-two')
        ->and(array_keys($packages->all()))->toBe($manifestNames);

    File::deleteDirectory($componentRoot);
    File::deleteDirectory($cacheRoot);
});

it('ignores tagged services that do not implement the reset contract', function (): void {
    app()->instance('capell.test-not-resettable', new stdClass);
    app()->tag(['capell.test-not-resettable'], Resettable::TAG);

    new FlushResettableState(app())->handle();

    expect(true)->toBeTrue();
});

it('registers singleton request-caching core services for Octane reset', function (): void {
    $resettableServices = collect(app()->tagged(Resettable::TAG));

    expect($resettableServices->contains(fn (object $service): bool => $service instanceof CapellCoreManager))->toBeTrue()
        ->and($resettableServices->contains(fn (object $service): bool => $service instanceof LockdownStore))->toBeTrue()
        ->and($resettableServices->contains(fn (object $service): bool => $service instanceof ImageUrlPolicy))->toBeFalse();
});

it('flushes resettable services when an Octane operation terminates', function (): void {
    $baseApplication = app();
    $sandbox = clone $baseApplication;
    $resettable = new class implements Resettable
    {
        public int $flushes = 0;

        public function flushOctaneState(): void
        {
            $this->flushes++;
        }
    };

    $sandbox->instance('capell.test-octane-resettable', $resettable);
    $sandbox->tag(['capell.test-octane-resettable'], Resettable::TAG);

    event(new readonly class($baseApplication, $sandbox) implements OperationTerminated
    {
        public function __construct(
            private Application $application,
            private Application $sandbox,
        ) {}

        public function app(): Application
        {
            return $this->application;
        }

        public function sandbox(): Application
        {
            return $this->sandbox;
        }
    });

    expect($resettable->flushes)->toBe(1)
        ->and(collect($baseApplication->tagged(Resettable::TAG))->contains($resettable))->toBeFalse();
});
