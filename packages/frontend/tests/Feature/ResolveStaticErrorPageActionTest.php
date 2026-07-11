<?php

declare(strict_types=1);

use Capell\Frontend\Actions\ResolveStaticErrorPageAction;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Illuminate\Support\Facades\File;

/**
 * In-memory fake store backed by a temp dir on disk.
 */
class ResolveStaticErrorPageTestStore implements StaticErrorPageStore
{
    public string $directory;

    public function __construct()
    {
        $this->directory = storage_path('framework/testing/resolve-error-' . uniqid());
    }

    public function exists(string $file): bool
    {
        return File::exists($this->fullPath($file));
    }

    public function path(string $file): ?string
    {
        return $this->fullPath($file);
    }

    public function put(string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->fullPath($file)));
        File::put($this->fullPath($file), $contents);
    }

    private function fullPath(string $file): string
    {
        return $this->directory . '/' . ltrim($file, '/');
    }
}

function makeStaticErrorPageStore(): ResolveStaticErrorPageTestStore
{
    return new ResolveStaticErrorPageTestStore;
}

beforeEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(ErrorPageManifestStore::class)->path());

    $store = $this->store ?? null;

    if ($store instanceof ResolveStaticErrorPageTestStore) {
        File::deleteDirectory($store->directory);
    }
});

it('returns the static html for a matching manifest entry', function (): void {
    $this->store = makeStaticErrorPageStore();
    $this->store->put('error/https.example.test/404/index.html', '<h1>Not found</h1>');

    app()->instance(StaticErrorPageStore::class, $this->store);

    resolve(ErrorPageManifestStore::class)->write([
        'sites' => [
            '1' => ['entries' => [[
                'scheme' => 'https',
                'domain' => 'example.test',
                'path' => '/',
                'status' => '404',
                'file' => 'error/https.example.test/404/index.html',
            ]]],
        ],
    ]);

    $result = ResolveStaticErrorPageAction::run('https', 'example.test', '/anything', '404');

    expect($result)->toBe('<h1>Not found</h1>');
});

it('chooses the longest matching path prefix', function (): void {
    $this->store = makeStaticErrorPageStore();
    $this->store->put('error/https.example.test/root/index.html', '<h1>root</h1>');
    $this->store->put('error/https.example.test/shop/index.html', '<h1>shop</h1>');

    app()->instance(StaticErrorPageStore::class, $this->store);

    resolve(ErrorPageManifestStore::class)->write([
        'sites' => [
            '1' => ['entries' => [
                [
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'status' => '404',
                    'file' => 'error/https.example.test/root/index.html',
                ],
                [
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/shop',
                    'status' => '404',
                    'file' => 'error/https.example.test/shop/index.html',
                ],
            ]],
        ],
    ]);

    $result = ResolveStaticErrorPageAction::run('https', 'example.test', '/shop/x', '404');

    expect($result)->toBe('<h1>shop</h1>');
});

it('returns null for a wrong status', function (): void {
    $this->store = makeStaticErrorPageStore();
    $this->store->put('error/https.example.test/404/index.html', '<h1>Not found</h1>');

    app()->instance(StaticErrorPageStore::class, $this->store);

    resolve(ErrorPageManifestStore::class)->write([
        'sites' => [
            '1' => ['entries' => [[
                'scheme' => 'https',
                'domain' => 'example.test',
                'path' => '/',
                'status' => '404',
                'file' => 'error/https.example.test/404/index.html',
            ]]],
        ],
    ]);

    expect(ResolveStaticErrorPageAction::run('https', 'example.test', '/', '500'))->toBeNull();
});

it('returns null for a wrong host', function (): void {
    $this->store = makeStaticErrorPageStore();
    $this->store->put('error/https.example.test/404/index.html', '<h1>Not found</h1>');

    app()->instance(StaticErrorPageStore::class, $this->store);

    resolve(ErrorPageManifestStore::class)->write([
        'sites' => [
            '1' => ['entries' => [[
                'scheme' => 'https',
                'domain' => 'example.test',
                'path' => '/',
                'status' => '404',
                'file' => 'error/https.example.test/404/index.html',
            ]]],
        ],
    ]);

    expect(ResolveStaticErrorPageAction::run('https', 'other.test', '/', '404'))->toBeNull();
});

it('returns null when the store is unbound', function (): void {
    app()->forgetInstance(StaticErrorPageStore::class);

    expect(app()->bound(StaticErrorPageStore::class))->toBeFalse();

    resolve(ErrorPageManifestStore::class)->write([
        'sites' => [
            '1' => ['entries' => [[
                'scheme' => 'https',
                'domain' => 'example.test',
                'path' => '/',
                'status' => '404',
                'file' => 'error/https.example.test/404/index.html',
            ]]],
        ],
    ]);

    expect(ResolveStaticErrorPageAction::run('https', 'example.test', '/', '404'))->toBeNull();
});

it('returns null when the manifest entry file is missing on disk', function (): void {
    $this->store = makeStaticErrorPageStore();
    app()->instance(StaticErrorPageStore::class, $this->store);

    resolve(ErrorPageManifestStore::class)->write([
        'sites' => [
            '1' => ['entries' => [[
                'scheme' => 'https',
                'domain' => 'example.test',
                'path' => '/',
                'status' => '404',
                'file' => 'error/https.example.test/404/index.html',
            ]]],
        ],
    ]);

    expect(ResolveStaticErrorPageAction::run('https', 'example.test', '/', '404'))->toBeNull();
});
