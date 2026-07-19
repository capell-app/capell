<?php

declare(strict_types=1);

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Static\StaticPageArtifactPathResolver;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(resolve(StaticPageArtifactStore::class)->root());
});

it('resolves safe static artifact paths under the site host folder', function (): void {
    $pageUrl = new PageUrl(['url' => '/about']);
    $siteDomain = new SiteDomain([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/en',
    ]);

    expect(resolve(StaticPageArtifactPathResolver::class)->pathForPageUrl($pageUrl, $siteDomain))
        ->toBe('https.example.test/en/about/index.html');
});

it('rejects traversal segments before static artifacts are written', function (): void {
    $pageUrl = new PageUrl(['url' => '/../../other-site']);
    $siteDomain = new SiteDomain([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/',
    ]);

    expect(fn (): string => resolve(StaticPageArtifactPathResolver::class)->pathForPageUrl($pageUrl, $siteDomain))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects direct store writes outside the artifact root', function (): void {
    $store = resolve(StaticPageArtifactStore::class);

    expect(fn (): null => $store->putHtml('../escape.html', '<html></html>'))
        ->toThrow(InvalidArgumentException::class);

});

it('falls back when a static artifact manifest is not a JSON object', function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    File::ensureDirectoryExists($store->root());
    File::put($store->manifestPath(), '["unexpected-list"]');

    expect($store->readManifest())->toBe([
        'generated_at' => null,
        'artifacts' => [],
    ]);
});
