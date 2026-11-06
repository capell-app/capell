<?php

declare(strict_types=1);

use Capell\Core\Tests\Unit\Support\Cache\Fixtures\CacheOriginContexts;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Tests\Fixtures\CoordinatedFragmentStore;
use Capell\Frontend\Tests\Fixtures\FragmentCacheTestStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;

$trustedProxies = [];
$trustedHeaderSet = -1;

beforeEach(function () use (&$trustedProxies, &$trustedHeaderSet): void {
    $trustedProxies = Request::getTrustedProxies();
    $trustedHeaderSet = Request::getTrustedHeaderSet();
    config(['app.url' => 'https://cache.example.test']);
});

afterEach(function () use (&$trustedProxies, &$trustedHeaderSet): void {
    Request::setTrustedProxies($trustedProxies, $trustedHeaderSet);
});

it('separates origin-bound fragments by port and invalidates every port through its surrogate', function (): void {
    $repository = new Repository(new ArrayStore);
    $fragments = new FragmentCache($repository);

    foreach ([8080, 8081] as $port) {
        app()->instance('request', Request::create('http://cache.example.test:' . $port));
        expect($fragments->remember('form', static fn (): string => 'form on ' . $port, surrogateKeys: ['page:1']))
            ->toBe('form on ' . $port);
    }

    $fragments->invalidateBySurrogateKey('page:1');

    foreach ([8080, 8081] as $port) {
        app()->instance('request', Request::create('http://cache.example.test:' . $port));
        expect($fragments->remember('form', static fn (): string => 'fresh on ' . $port))
            ->toBe('fresh on ' . $port);
    }
});

it('reuses legacy fragment keys on explicit and implicit standard ports', function (string $origin): void {
    $repository = new Repository(new ArrayStore);
    $repository->put('fragment:namespace', 'existing-namespace', 3600);
    $repository->put('fragment:existing-namespace:value:form', 'existing form', 3600);

    app()->instance('request', Request::create($origin));

    expect(new FragmentCache($repository)->remember('form', static fn (): string => 'unexpected miss'))
        ->toBe('existing form');
})->with(['http://cache.example.test', 'http://cache.example.test:80', 'https://cache.example.test', 'https://cache.example.test:443']);

it('flushes only fragments including fragments without surrogate keys', function (bool $supportsTags): void {
    $directory = sys_get_temp_dir() . '/capell-fragment-test-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $repository = new Repository($supportsTags ? new ArrayStore : new FileStore($files, $directory));
    $fragments = new FragmentCache($repository);

    try {
        $repository->put('application-sentinel', 'keep', 3600);
        $fragments->remember('plain', static fn (): string => 'old plain');
        $fragments->remember('tagged', static fn (): string => 'old tagged', surrogateKeys: ['page:1']);
        $fragments->flush();

        expect($repository->get('application-sentinel'))->toBe('keep')
            ->and($fragments->remember('plain', static fn (): string => 'new plain'))->toBe('new plain')
            ->and($fragments->remember('tagged', static fn (): string => 'new tagged', surrogateKeys: ['page:1']))->toBe('new tagged');
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['taggable' => true, 'non-tagging' => false]);

it('removes surrogate ownership when fragments are invalidated or flushed', function (): void {
    $repository = new Repository(new ArrayStore);
    $fragments = new FragmentCache($repository);
    $fragments->remember('shared', static fn (): string => 'old', surrogateKeys: ['page:1', 'page:2']);
    $fragments->invalidateBySurrogateKey('page:1');
    $fragments->remember('shared', static fn (): string => 'new');
    $fragments->invalidateBySurrogateKey('page:2');

    expect($fragments->remember('shared', static fn (): string => 'unexpected'))->toBe('new');

    $fragments->flush();
    $fragments->remember('shared', static fn (): string => 'after flush');
    $fragments->invalidateBySurrogateKey('page:1');

    expect($fragments->remember('shared', static fn (): string => 'unexpected'))->toBe('after flush');
});

it('invalidates numeric surrogate keys without losing other fragment ownership', function (bool $supportsTags): void {
    $directory = sys_get_temp_dir() . '/capell-fragment-test-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $repository = new Repository($supportsTags ? new ArrayStore : new FileStore($files, $directory));
    $fragments = new FragmentCache($repository);

    try {
        $repository->put('application-sentinel', 'keep', 3600);
        $fragments->remember('numeric', static fn (): string => 'old', surrogateKeys: ['42', 'page:42']);
        $fragments->remember('zero', static fn (): string => 'old zero', surrogateKeys: ['0']);
        $fragments->remember('other', static fn (): string => 'keep fragment', surrogateKeys: ['page:43']);

        $fragments->invalidateBySurrogateKey('42');
        $fragments->invalidateBySurrogateKey('0');

        expect($fragments->remember('numeric', static fn (): string => 'new'))->toBe('new')
            ->and($fragments->remember('zero', static fn (): string => 'new zero'))->toBe('new zero')
            ->and($fragments->remember('other', static fn (): string => 'unexpected'))->toBe('keep fragment')
            ->and($repository->get('application-sentinel'))->toBe('keep');

        $fragments->invalidateBySurrogateKey('page:42');
        expect($fragments->remember('numeric', static fn (): string => 'unexpected'))->toBe('new');
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['taggable' => true, 'non-tagging' => false]);

it('retains the literal pre-change fragment key across standard-port warm serve and purge', function (array|string|null $context, bool $trusted): void {
    $store = new ArrayStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'fixed', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('console');
    $fragments->remember('public', static fn (): string => 'warmed', surrogateKeys: ['page:1']);
    CacheOriginContexts::bind($context, $trusted);

    expect($fragments->remember('public', static fn (): string => 'miss', surrogateKeys: ['page:2']))->toBe('warmed')
        ->and($repository->get('fragment:fixed:value:public'))->toBe('warmed')
        ->and(array_values(array_filter(array_keys($store->all()), static fn (string $key): bool => str_contains($key, ':value:'))))
        ->toBe(['fragment:fixed:value:public']);
    $fragments->remember('other', static fn (): string => 'keep', surrogateKeys: ['page:3']);
    $repository->put('unrelated', 'sentinel', 60);

    CacheOriginContexts::bind(null);
    $fragments->invalidateBySurrogateKey('page:1');
    CacheOriginContexts::bind($context, $trusted);
    expect($repository->get('fragment:fixed:value:public'))->toBeNull()
        ->and($fragments->remember('public', static fn (): string => 'refilled'))->toBe('refilled')
        ->and($fragments->remember('other', static fn (): string => 'miss'))->toBe('keep')
        ->and($repository->get('unrelated'))->toBe('sentinel');
    $fragments->invalidateBySurrogateKey('page:2');
    CacheOriginContexts::bind('console');
    expect($fragments->remember('public', static fn (): string => 'miss'))->toBe('refilled');
})->with(CacheOriginContexts::standard());

it('agrees on non-standard-port fragment warming serving and purging through APP_URL fallback', function (array|string|null $context, bool $trusted): void {
    config(['app.url' => 'https://cache.example.test:8443']);
    $repository = new Repository(new ArrayStore);
    $repository->put('fragment:namespace', 'fixed', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind(null);
    $fragments->remember('public', static fn (): string => 'warmed', surrogateKeys: ['page:1', 'page:2']);
    CacheOriginContexts::bind($context, $trusted);
    expect($fragments->remember('public', static fn (): string => 'miss', surrogateKeys: ['page:1']))->toBe('warmed');

    foreach (['https://cache.example.test:8444', 'http://cache.example.test:8443', 'https://cache.example.test'] as $other) {
        CacheOriginContexts::bind($other);
        expect($fragments->remember('public', static fn (): string => 'other', surrogateKeys: ['page:1', 'page:2']))->toBe('other');
        $fragments->remember('unrelated', static fn (): string => 'keep', surrogateKeys: ['page:3']);
    }

    $repository->put('unrelated-store-key', 'sentinel', 60);
    CacheOriginContexts::bind('console');
    $fragments->invalidateBySurrogateKey('page:1');
    CacheOriginContexts::bind($context, $trusted);
    expect($fragments->remember('public', static fn (): string => 'fresh'))->toBe('fresh');
    foreach (['https://cache.example.test:8444', 'http://cache.example.test:8443', 'https://cache.example.test'] as $other) {
        CacheOriginContexts::bind($other);
        expect($fragments->remember('public', static fn (): string => 'fresh other'))->toBe('fresh other')
            ->and($fragments->remember('unrelated', static fn (): string => 'miss'))->toBe('keep');
    }

    $fragments->invalidateBySurrogateKey('page:2');
    CacheOriginContexts::bind($context, $trusted);
    expect($fragments->remember('public', static fn (): string => 'miss'))->toBe('fresh')
        ->and($repository->get('unrelated-store-key'))->toBe('sentinel');
})->with(CacheOriginContexts::nonStandard());

it('cannot alias a caller-supplied standard-port fragment key or delete its value', function (): void {
    $store = new ArrayStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'fixed', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('https://cache.example.test:8443');
    $fragments->remember('alias', static fn (): string => 'port 8443', surrogateKeys: ['target']);
    $variantKey = array_values(array_filter(array_keys($store->all()), static fn (string $key): bool => ! str_contains($key, ':surrogate:') && $key !== 'fragment:namespace'))[0];

    CacheOriginContexts::bind('https://cache.example.test');
    $logicalKeys = ['alias:origin:https:8443', substr($variantKey, strlen('fragment:fixed:value:'))];
    foreach ($logicalKeys as $logicalKey) {
        expect($fragments->remember($logicalKey, static fn (): string => 'unrelated standard', surrogateKeys: ['unrelated']))->toBe('unrelated standard');
    }

    $fragments->invalidateBySurrogateKey('target');
    foreach ($logicalKeys as $logicalKey) {
        expect($repository->get('fragment:fixed:value:' . $logicalKey))->toBe('unrelated standard');
    }

    CacheOriginContexts::bind('https://cache.example.test:8443');
    expect($fragments->remember('alias', static fn (): string => 'fresh'))->toBe('fresh');
});

it('flushes fragment variants on every port while preserving the shared store', function (): void {
    $repository = new Repository(new ArrayStore);
    $fragments = new FragmentCache($repository);
    $repository->put('unrelated-store-key', 'sentinel', 60);
    foreach ([443, 8443, 8444] as $port) {
        CacheOriginContexts::bind('https://cache.example.test:' . $port);
        $fragments->remember('public', static fn (): string => 'old');
    }

    CacheOriginContexts::bind(null);
    $fragments->flush();
    foreach ([443, 8443, 8444] as $port) {
        CacheOriginContexts::bind('https://cache.example.test:' . $port);
        expect($fragments->remember('public', static fn (): string => 'fresh'))->toBe('fresh');
    }

    expect($repository->get('unrelated-store-key'))->toBe('sentinel');
});

it('serves a tracked cache hit without taking the surrogate lock or writing', function (): void {
    $store = new FragmentCacheTestStore;
    $repository = new Repository($store);
    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('console');

    expect($fragments->remember('tracked', static fn (): string => 'cached', surrogateKeys: ['page:1']))
        ->toBe('cached');

    $store->lockCalls = 0;
    $store->putCalls = 0;

    $callbackCalled = false;

    expect($fragments->remember('tracked', function () use (&$callbackCalled): string {
        $callbackCalled = true;

        return 'unexpected';
    }, surrogateKeys: ['page:1']))
        ->toBe('cached')
        ->and($callbackCalled)->toBeFalse()
        ->and($store->lockCalls)->toBe(0)
        ->and($store->putCalls)->toBe(0);
});

it('repairs missing surrogate membership for a tracked cache hit', function (): void {
    $store = new FragmentCacheTestStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'fixed', 3600);
    $repository->put('fragment:fixed:value:tracked', 'cached', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('console');
    $store->lockCalls = 0;
    $store->putCalls = 0;

    expect($fragments->remember('tracked', static fn (): string => 'unexpected', surrogateKeys: ['page:1']))
        ->toBe('cached')
        ->and($store->lockCalls)->toBe(1)
        ->and($store->putCalls)->toBe(1)
        ->and($repository->get('fragment:fixed:surrogate:map'))
        ->toBe(['page:1' => ['fragment:fixed:value:tracked']]);
});

it('never serves a value republished into the old namespace after flush', function (): void {
    $store = new FragmentCacheTestStore;
    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'old-namespace', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('console');
    $store->beforeMapWrite = $fragments->flush(...);

    expect($fragments->remember('late', static fn (): string => 'old', surrogateKeys: ['late-page']))
        ->toBe('old')
        ->and($store->get('fragment:old-namespace:value:late'))->toBe('old')
        ->and($store->get('fragment:old-namespace:surrogate:map'))->toBe([
            'late-page' => ['fragment:old-namespace:value:late'],
        ])
        ->and($fragments->remember('late', static fn (): string => 'fresh'))->toBe('fresh');
});

it('does not write a fragment when surrogate map publication fails', function (): void {
    $store = new FragmentCacheTestStore;
    $store->failMapWrites = true;

    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'fixed', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('console');

    expect($fragments->remember('map-failure', static fn (): string => 'rendered', surrogateKeys: ['map-failure']))
        ->toBe('rendered')
        ->and($store->get('fragment:fixed:value:map-failure'))->toBeNull()
        ->and($store->get('fragment:fixed:surrogate:map'))->toBeNull();
});

it('leaves map membership when fragment value publication fails', function (): void {
    $store = new FragmentCacheTestStore;
    $store->failFragmentWrites = true;

    $repository = new Repository($store);
    $repository->put('fragment:namespace', 'fixed', 3600);

    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('console');

    expect($fragments->remember('value-failure', static fn (): string => 'rendered', surrogateKeys: ['value-failure']))
        ->toBe('rendered')
        ->and($store->get('fragment:fixed:value:value-failure'))->toBeNull()
        ->and($store->get('fragment:fixed:surrogate:map'))->toBe([
            'value-failure' => ['fragment:fixed:value:value-failure'],
        ]);
});

it('returns rendered content without publishing when the surrogate lock fails', function (): void {
    $store = Mockery::mock(ArrayStore::class)->makePartial();
    $store->shouldReceive('lock')->once()->andThrow(new LockTimeoutException);
    $store->put('fragment:namespace', 'fixed', 3600);
    $repository = new Repository($store);
    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('https://cache.example.test:8443');

    expect($fragments->remember('locked', static fn (): string => 'rendered', surrogateKeys: ['lock-page']))
        ->toBe('rendered')
        ->and($store->get('fragment:fixed:origin-value:' . hash('sha256', serialize(['locked', 'https://cache.example.test:8443']))))
        ->toBeNull()
        ->and($store->get('fragment:fixed:surrogate:map'))->toBeNull();
});

it('keeps surrogate coordination free of process-wide mutable state', function (): void {
    expect(new ReflectionClass(FragmentCache::class)->getStaticProperties())->toBe([]);
});

it('purges variants recorded by separate cache workers during a real race', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Fragment cache race fixtures require pcntl_fork.');
    }

    $directory = sys_get_temp_dir() . '/capell-fragment-race-' . bin2hex(random_bytes(8));
    $barrierDirectory = $directory . '/barrier';
    $files = new Filesystem;
    $files->makeDirectory($barrierDirectory, 0777, true);

    $origins = ['https://cache.example.test:8443', 'https://cache.example.test:8444'];
    $children = [];

    try {
        $bootstrap = new FragmentCache(new Repository(new FileStore($files, $directory)));
        CacheOriginContexts::bind('console');
        $bootstrap->remember('bootstrap', static fn (): string => 'bootstrap');

        foreach ($origins as $index => $origin) {
            $pid = pcntl_fork();
            throw_if($pid === -1, RuntimeException::class, 'Unable to fork a fragment cache race worker.');

            if ($pid === 0) {
                try {
                    $store = new CoordinatedFragmentStore($files, $directory, $barrierDirectory, $index, count($origins));
                    $fragments = new FragmentCache(new Repository($store));
                    CacheOriginContexts::bind($origin);
                    $fragments->remember('same', static fn (): string => 'old ' . $index, surrogateKeys: ['race-page']);
                    file_put_contents($barrierDirectory . '/result-' . $index, 'ok');
                    pcntl_exec('/usr/bin/true');
                } catch (Throwable $exception) {
                    file_put_contents($barrierDirectory . '/result-' . $index, $exception::class . ': ' . $exception->getMessage());
                    pcntl_exec('/usr/bin/false');
                }
            }

            $children[$index] = $pid;
        }

        foreach (array_keys($origins) as $index) {
            $deadline = microtime(true) + 15;
            while (! is_file($barrierDirectory . '/result-' . $index)) {
                throw_if(microtime(true) > $deadline, RuntimeException::class, 'Fragment cache race worker did not finish.');
                Sleep::usleep(1_000);
            }
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            throw_unless(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, RuntimeException::class, 'Fragment cache race worker failed.');
        }

        $fragments = new FragmentCache(new Repository(new FileStore($files, $directory)));
        CacheOriginContexts::bind('console');
        $fragments->invalidateBySurrogateKey('race-page');
        foreach ($origins as $origin) {
            CacheOriginContexts::bind($origin);
            expect($fragments->remember('same', static fn (): string => 'fresh'))->toBe('fresh');
        }
    } finally {
        $files->deleteDirectory($directory);
        CacheOriginContexts::bind(null);
    }
});
