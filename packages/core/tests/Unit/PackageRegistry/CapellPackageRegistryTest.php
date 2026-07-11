<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;

function makeManifest(string $name, array $surfaces = ['admin'], ?string $namespace = null, array $providers = []): CapellManifestData
{
    return CapellManifestData::fromArray(capellManifestV3Array($name, $surfaces, $namespace, $providers));
}

it('stores and retrieves a manifest by package name', function (): void {
    $registry = new CapellPackageRegistry;
    $manifest = makeManifest('capell-app/blog', ['admin', 'frontend']);

    $registry->fill(['capell-app/blog' => $manifest]);

    expect($registry->get('capell-app/blog'))->toBe($manifest)
        ->and($registry->has('capell-app/blog'))->toBeTrue()
        ->and($registry->has('capell-app/missing'))->toBeFalse();
});

it('returns null for unknown packages', function (): void {
    $registry = new CapellPackageRegistry;

    expect($registry->get('capell-app/missing'))->toBeNull();
});

it('returns all registered manifests', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/blog' => makeManifest('capell-app/blog'),
        'capell-app/maps' => makeManifest('capell-app/maps'),
    ]);

    expect($registry->all())->toHaveCount(2);
});

it('builds namespace map from explicit namespace fields', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/core' => makeManifest('capell-app/core', namespace: 'Capell\\Core'),
        'capell-app/blog' => makeManifest('capell-app/blog', namespace: 'Capell\\Blog'),
    ]);

    expect($registry->namespaceMap())->toBe([
        'Capell\\Core\\' => 'core',
        'Capell\\Blog\\' => 'blog',
    ]);
});

it('builds namespace map by deriving from providers when namespace field is absent', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/seo-suite' => makeManifest(
            name: 'capell-app/seo-suite',
            providers: ['runtime' => ['Capell\\SeoSuite\\Providers\\SeoSuiteServiceProvider']],
        ),
    ]);

    expect($registry->namespaceMap())->toBe([
        'Capell\\SeoSuite\\' => 'seo-suite',
    ]);
});

// Regression: namespaceMap derived the short name via `(int) strrpos(...) + 1`.
// For a slash-less package name `strrpos` returns false, `(int) false === 0`
// and `+ 1` chopped the first character (e.g. "blog" -> "log").
it('keeps the whole short name for a package name without a slash', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'blog' => makeManifest('blog', namespace: 'Capell\\Blog'),
    ]);

    expect($registry->namespaceMap())->toBe([
        'Capell\\Blog\\' => 'blog',
    ]);
});

it('omits packages from namespace map when namespace cannot be resolved', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/no-namespace' => makeManifest('capell-app/no-namespace'),
    ]);

    expect($registry->namespaceMap())->toBeEmpty();
});

it('filters manifests by surface', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/blog' => makeManifest('capell-app/blog', ['admin', 'frontend']),
        'capell-app/insights' => makeManifest('capell-app/insights', ['admin']),
        'capell-app/maps' => makeManifest('capell-app/maps', ['frontend']),
    ]);

    $adminPackages = $registry->forContext('admin');
    $frontendPackages = $registry->forContext('frontend');

    expect($adminPackages)->toHaveCount(2)
        ->and($frontendPackages)->toHaveCount(2);
});

it('preserves product packaging metadata', function (): void {
    $manifest = CapellManifestData::fromArray([
        ...capellManifestV3Array('capell-app/form-builder', ['admin', 'frontend']),
        'product' => ['group' => 'Capell FormBuilder', 'tier' => 'premium', 'bundle' => 'form-builder'],
    ]);

    expect($manifest->productGroup)->toBe('Capell FormBuilder')
        ->and($manifest->tier)->toBe('premium')
        ->and($manifest->bundle)->toBe('form-builder')
        ->and($manifest->toArray())->toMatchArray([
            'product' => [
                'group' => 'Capell FormBuilder',
                'tier' => 'premium',
                'bundle' => 'form-builder',
            ],
        ]);
});
