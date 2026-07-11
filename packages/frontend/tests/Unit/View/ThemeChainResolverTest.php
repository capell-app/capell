<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Exceptions\ThemeChainException;
use Capell\Frontend\Support\View\ThemeChainResolver;

function makeThemeManifest(string $name, ?string $extends = null, ?string $themeKey = null): CapellManifestData
{
    return CapellManifestData::fromArray(capellManifestV3Array(
        name: $name,
        surfaces: ['frontend'],
        overrides: [
            'kind' => 'theme',
            'extends' => $extends,
            'themeKey' => $themeKey,
        ],
    ));
}

it('returns no package paths for the built in default theme', function (): void {
    $registry = new CapellPackageRegistry;
    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'default']);
    $paths = $resolver->resolve($theme);

    expect($paths)->toBe([]);
});

it('resolves single-level package theme with no extends', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/theme-corporate' => makeThemeManifest('capell-app/theme-corporate', themeKey: 'corporate'),
    ]);

    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'corporate']);
    $paths = $resolver->resolve($theme);

    expect($paths)->toHaveCount(1);
});

it('resolves two-level extends chain, most-specific first', function (): void {
    $registry = new CapellPackageRegistry;
    // `extends` references the parent by its theme key ('base'), not its
    // composer package name — the same key space the starting theme resolves
    // through.
    $registry->fill([
        'vendor/base-theme' => makeThemeManifest('vendor/base-theme', themeKey: 'base'),
        'vendor/my-theme' => makeThemeManifest('vendor/my-theme', extends: 'base'),
    ]);

    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'my']);
    $paths = $resolver->resolve($theme);

    expect($paths)->toHaveCount(2);
    // Don't assert path contents since packages aren't actually installed
});

it('resolves a child theme extending the foundation theme by its "default" key', function (): void {
    // Mirrors production: the foundation package is named
    // "capell-app/foundation-theme" but registers theme key "default", and
    // every vertical theme declares extends: "default". Resolving extends by
    // package name (the old behaviour) threw here; by key it walks the chain.
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/foundation-theme' => makeThemeManifest('capell-app/foundation-theme', themeKey: 'default'),
        'capell-app/theme-api-platform' => makeThemeManifest('capell-app/theme-api-platform', extends: 'default', themeKey: 'api-platform'),
    ]);

    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'api-platform']);

    expect($resolver->resolve($theme))->toHaveCount(2);
});

it('resolves explicit child theme key over derived package key', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/theme-corporate' => makeThemeManifest('capell-app/theme-corporate', themeKey: 'corporate'),
    ]);

    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'corporate']);

    expect($resolver->resolve($theme))->toHaveCount(1);
});

it('throws ThemeChainException when a theme chain contains a cycle', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/theme-a' => makeThemeManifest('vendor/theme-a', extends: 'b', themeKey: 'a'),
        'vendor/theme-b' => makeThemeManifest('vendor/theme-b', extends: 'a', themeKey: 'b'),
    ]);

    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'a']);

    expect(fn (): array => $resolver->resolve($theme))->toThrow(ThemeChainException::class);
});

it('throws ThemeChainException when extends target not in registry', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/my-theme' => makeThemeManifest('vendor/my-theme', extends: 'missing-base'),
    ]);

    $resolver = new ThemeChainResolver($registry, cachePath: '/nonexistent/path.php');

    $theme = Theme::factory()->make(['key' => 'my']);

    expect(fn (): array => $resolver->resolve($theme))->toThrow(ThemeChainException::class);
});

it('returns theme paths from compiled cache without touching registry', function (): void {
    $cacheFile = tempnam(sys_get_temp_dir(), 'capell-theme-chain-test');
    file_put_contents($cacheFile, '<?php return ["default" => ["/cached/path/views"]];');

    $registry = new CapellPackageRegistry;
    // Registry is empty — if resolver hits it, there'd be no result

    $resolver = new ThemeChainResolver($registry, cachePath: $cacheFile);

    $theme = Theme::factory()->make(['key' => 'default']);
    $paths = $resolver->resolve($theme);

    unlink($cacheFile);

    expect($paths)->toBe(['/cached/path/views']);
});
