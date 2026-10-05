<?php

declare(strict_types=1);

use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

$trustedProxies = [];
$trustedHeaderSet = -1;

beforeEach(function () use (&$trustedProxies, &$trustedHeaderSet): void {
    $trustedProxies = Request::getTrustedProxies();
    $trustedHeaderSet = Request::getTrustedHeaderSet();

    config([
        'app.url' => 'https://cache.example.test',
        'cache.default' => 'array',
        'capell.disable_cache' => false,
        'capell.disable_cache_save_keys' => [],
    ]);

    Route::get('/cached-page', static fn (Request $request): string => resolve(CapellCacheManager::class)->rememberCache(
        'html:cached-page',
        static fn (): string => '<p>' . $request->getSchemeAndHttpHost() . '</p>',
    ));
});

afterEach(function () use (&$trustedProxies, &$trustedHeaderSet): void {
    Request::setTrustedProxies($trustedProxies, $trustedHeaderSet);
});

it('serves independent cached copies of the same page on different ports', function (): void {
    $this->get('http://cache.example.test:8080/cached-page')
        ->assertOk()->assertContent('<p>http://cache.example.test:8080</p>');
    $this->get('http://cache.example.test:8081/cached-page')
        ->assertOk()->assertContent('<p>http://cache.example.test:8081</p>');

    resolve(CapellCacheManager::class)->flushLocalCache();

    $this->get('http://cache.example.test:8080/cached-page')
        ->assertOk()->assertContent('<p>http://cache.example.test:8080</p>');
    $this->get('http://cache.example.test:8081/cached-page')
        ->assertOk()->assertContent('<p>http://cache.example.test:8081</p>');
});

it('separates schemes using the same non-standard port', function (): void {
    $this->get('http://cache.example.test:8080/cached-page')->assertContent('<p>http://cache.example.test:8080</p>');
    $this->get('https://cache.example.test:8080/cached-page')->assertContent('<p>https://cache.example.test:8080</p>');
});

it('preserves the byte-identical legacy identity and existing entries on standard ports', function (string $origin): void {
    app()->instance('request', Request::create($origin . '/cached-page'));
    $manager = resolve(CapellCacheManager::class);
    $legacyKey = hash('sha256', serialize([
        substr(hash('xxh128', 'cache.example.test'), 0, 8),
        0,
        [],
        'html:cached-page',
    ]));
    $store = Cache::tags(config('capell.cache_tag', 'capell-app'));
    $store->put($legacyKey, 'existing production entry', 60);

    expect(new ReflectionMethod($manager, 'normalizeCacheKey')->invoke($manager, 'html:cached-page'))
        ->toBe($legacyKey)
        ->and($manager->getFromCache('html:cached-page'))->toBe('existing production entry');
})->with(['http://cache.example.test', 'http://cache.example.test:80', 'https://cache.example.test', 'https://cache.example.test:443']);

it('warms and purges the same entry as serving without affecting another port', function (): void {
    foreach ([8080, 8081] as $port) {
        app()->instance('request', Request::create('http://cache.example.test:' . $port . '/cached-page'));
        (new CapellCacheManager)->setToCache('html:cached-page', 'warmed on ' . $port);
    }

    $this->get('http://cache.example.test:8080/cached-page')->assertContent('warmed on 8080');
    $this->get('http://cache.example.test:8081/cached-page')->assertContent('warmed on 8081');

    app()->instance('request', Request::create('http://cache.example.test:8080/cached-page'));
    (new CapellCacheManager)->removeCacheKey('html:cached-page');
    resolve(CapellCacheManager::class)->flushLocalCache();

    $this->get('http://cache.example.test:8080/cached-page')->assertContent('<p>http://cache.example.test:8080</p>');
    $this->get('http://cache.example.test:8081/cached-page')->assertContent('warmed on 8081');
});

it('uses trusted forwarded scheme and port rather than the internal proxy port', function (int $port): void {
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    $proxied = Request::create('http://cache.example.test:9000/cached-page', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => (string) $port,
    ]);
    app()->instance('request', $proxied);
    (new CapellCacheManager)->setToCache('html:cached-page', 'warmed through proxy');

    $this->get('https://cache.example.test:' . $port . '/cached-page')->assertContent('warmed through proxy');
    $this->get('http://cache.example.test:9000/cached-page')->assertContent('<p>http://cache.example.test:9000</p>');
})->with([443, 8443]);

it('does not trust forwarded ports from an untrusted client', function (): void {
    $request = Request::create('http://cache.example.test:8080/cached-page', server: [
        'HTTP_X_FORWARDED_PORT' => '8081',
    ]);
    app()->instance('request', $request);
    (new CapellCacheManager)->setToCache('html:cached-page', 'actual origin');

    $this->get('http://cache.example.test:8080/cached-page')->assertContent('actual origin');
    $this->get('http://cache.example.test:8081/cached-page')->assertContent('<p>http://cache.example.test:8081</p>');
});
