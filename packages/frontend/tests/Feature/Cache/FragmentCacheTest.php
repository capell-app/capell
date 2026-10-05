<?php

declare(strict_types=1);

use Capell\Core\Tests\Unit\Support\Cache\Fixtures\CacheOriginContexts;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Tests\Fixtures\InterleavedFragmentRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;

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

it('characterises lost surrogate membership when repository map writes interleave', function (): void {
    // Repository and Store do not guarantee atomic map updates or lock support.
    // Pin the existing limitation until coordination can cover all supported stores.
    $repository = new InterleavedFragmentRepository(new ArrayStore);
    $fragments = new FragmentCache($repository);
    CacheOriginContexts::bind('https://cache.example.test:8443');
    $repository->beforeMapWrite = static function () use ($fragments): void {
        CacheOriginContexts::bind('https://cache.example.test:8444');
        $fragments->remember('same', static fn (): string => 'second', surrogateKeys: ['race-page']);
        CacheOriginContexts::bind('https://cache.example.test:8443');
    };
    $fragments->remember('same', static fn (): string => 'first', surrogateKeys: ['race-page']);
    CacheOriginContexts::bind('console');
    $fragments->invalidateBySurrogateKey('race-page');
    CacheOriginContexts::bind('https://cache.example.test:8443');
    expect($fragments->remember('same', static fn (): string => 'fresh first'))->toBe('fresh first');
    CacheOriginContexts::bind('https://cache.example.test:8444');
    expect($fragments->remember('same', static fn (): string => 'unexpected miss'))->toBe('second');
});
