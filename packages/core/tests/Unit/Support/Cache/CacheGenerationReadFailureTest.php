<?php

declare(strict_types=1);

use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Tests\Unit\Support\Cache\Fixtures\GenerationReadFailureStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

it('fails closed instead of returning retained confidential content when a generation read fails', function (bool $taggable, string $generation, string $reader): void {
    $store = new GenerationReadFailureStore;
    $repository = $taggable ? new Repository($store) : new class($store) extends Repository
    {
        #[Override]
        public function supportsTags(): bool
        {
            return false;
        }
    };
    Cache::extend('generation-read-failure', fn (): Repository => $repository);
    config([
        'cache.default' => 'generation-read-failure',
        'cache.stores.generation-read-failure.driver' => 'generation-read-failure',
        'capell.cache_tag' => 'generation-read-failure',
        'capell.disable_cache' => false,
        'capell.disable_cache_save_keys' => [],
    ]);
    Cache::purge('generation-read-failure');

    $freshManager = static function (): CapellCacheManager {
        app()->instance('request', Request::create('https://cache.example.test'));
        $manager = new CapellCacheManager;
        $manager->registerCacheInvalidationPattern('page-*');

        return $manager;
    };

    $manager = $freshManager();
    $manager->setToCache('page-private', 'CONFIDENTIAL');

    $retainedKeys = array_keys(array_filter($store->all(), static fn (array $entry): bool => $entry['value'] === 'CONFIDENTIAL'));
    expect($retainedKeys)->toHaveCount(1);
    $retainedKey = $retainedKeys[0];

    $patternKey = 'capell.cache.pattern-generation.' . hash('sha256', 'page-*');
    $namespaceKey = 'capell.cache.generation.generation-read-failure';
    $store->failPatternWrites = $generation === 'namespace';
    $manager->invalidateCachePattern('page-*');

    if ($generation === 'namespace') {
        expect($repository->get($patternKey, 0))->toBe(0);
        $manager->flushCacheOrFail();
        expect($repository->get($namespaceKey))->toBe(1);
    } else {
        expect($repository->get($patternKey))->toBe(1);
    }

    expect($store->get($retainedKey))->toBe('CONFIDENTIAL')
        ->and($freshManager()->getFromCache('page-private'))->toBeNull();

    $manager = $freshManager();
    $store->failNextRead = $generation === 'namespace' ? $namespaceKey : $patternKey;
    $read = match ($reader) {
        'rememberCache' => fn (): mixed => $manager->rememberCache('page-private', static fn (): string => 'PUBLIC'),
        'getFreshFromCache' => fn (): mixed => $manager->getFreshFromCache('page-private'),
        'cacheExists' => fn (): bool => $manager->cacheExists('page-private'),
        default => fn (): mixed => $manager->getFromCache('page-private'),
    };

    expect($read)->toThrow(RuntimeException::class, 'Generation read failed.');

    // Only the generation GET failed; retained content remains readable by the backend.
    expect($store->get($retainedKey))->toBe('CONFIDENTIAL')
        ->and($manager->getFromCache('page-private'))->toBeNull()
        ->and($manager->rememberCache('page-private', static fn (): string => 'PUBLIC'))->toBe('PUBLIC');
})->with(['tagged' => true, 'untagged' => false])
    ->with(['namespace', 'pattern'])
    ->with(['getFromCache', 'rememberCache', 'getFreshFromCache', 'cacheExists']);
