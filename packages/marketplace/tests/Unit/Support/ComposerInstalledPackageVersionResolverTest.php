<?php

declare(strict_types=1);

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Support\ComposerInstalledPackageVersionResolver;
use Composer\InstalledVersions;

it('binds the production installed version resolver through the Marketplace service provider', function (): void {
    expect(resolve(MarketplaceInstalledPackageVersionResolver::class))
        ->toBeInstanceOf(ComposerInstalledPackageVersionResolver::class)
        ->and(resolve(MarketplaceInstalledPackageVersionResolver::class)->prettyVersion('package/that-is-not-installed'))
        ->toBeNull();
});

it('reads changed installed versions from disk without reusing a worker snapshot', function (): void {
    $vendorPath = sys_get_temp_dir() . '/marketplace-versions-' . bin2hex(random_bytes(8));
    mkdir($vendorPath . '/composer', recursive: true);
    $path = $vendorPath . '/composer/installed.json';
    $resolver = new ComposerInstalledPackageVersionResolver($vendorPath);
    $write = function (string $version) use ($path): void {
        file_put_contents($path, json_encode([
            'packages' => [['name' => 'publisher/extension', 'version' => $version]],
        ], JSON_THROW_ON_ERROR));
        clearstatcache(true, $path);
    };

    try {
        $write('2.1.0');
        expect($resolver->prettyVersion('publisher/extension'))->toBe('2.1.0');
        $write('2.5.0');
        expect($resolver->prettyVersion('publisher/extension'))->toBe('2.5.0');
        unlink($path);
        expect($resolver->prettyVersion('publisher/extension'))->toBeNull();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }

        rmdir($vendorPath . '/composer');
        rmdir($vendorPath);
    }
});

it('preserves the Composer root version for foundation compatibility checks', function (): void {
    $root = InstalledVersions::getRootPackage();

    expect(new ComposerInstalledPackageVersionResolver()->prettyVersion($root['name']))->toBe($root['pretty_version']);
});
