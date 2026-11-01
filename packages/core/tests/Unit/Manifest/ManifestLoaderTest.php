<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Composer\Autoload\ClassLoader;

function makeManifestFile(array $data, ?array $composerJson = null): string
{
    $directory = sys_get_temp_dir() . '/capell-manifest-' . bin2hex(random_bytes(6));
    mkdir($directory, recursive: true);

    file_put_contents($directory . '/capell.json', json_encode($data, JSON_THROW_ON_ERROR));

    if ($composerJson !== null) {
        file_put_contents($directory . '/composer.json', json_encode($composerJson, JSON_THROW_ON_ERROR));
    }

    return $directory . '/capell.json';
}

it('loads a valid manifest v3 capell.json from a given path', function (): void {
    $path = makeManifestFile(manifestV3LoaderFixture(), manifestV3LoaderComposerJson());

    $loader = new ManifestLoader(new ManifestValidator);
    $manifest = $loader->load($path);

    expect($manifest)->toBeInstanceOf(CapellManifestData::class)
        ->and($manifest->manifestVersion)->toBe(3)
        ->and($manifest->name)->toBe('vendor/package')
        ->and($manifest->capellApiVersion)->toBe('^1.0')
        ->and($manifest->kind)->toBe('package');
});

it('falls back to the composer support docs url when the manifest omits a documentation url', function (): void {
    $composerJson = manifestV3LoaderComposerJson();
    $composerJson['support'] = ['docs' => 'https://docs.capell.app/packages/package'];

    $path = makeManifestFile(manifestV3LoaderFixture(), $composerJson);

    $manifest = new ManifestLoader(new ManifestValidator)->load($path);

    expect($manifest->documentationUrl)->toBe('https://docs.capell.app/packages/package');
});

it('prefers the manifest documentation url over the composer support docs url', function (): void {
    $manifestData = manifestV3LoaderFixture();
    $manifestData['documentationUrl'] = 'https://docs.capell.app/packages/manifest';

    $composerJson = manifestV3LoaderComposerJson();
    $composerJson['support'] = ['docs' => 'https://docs.capell.app/packages/composer'];

    $path = makeManifestFile($manifestData, $composerJson);

    $manifest = new ManifestLoader(new ManifestValidator)->load($path);

    expect($manifest->documentationUrl)->toBe('https://docs.capell.app/packages/manifest');
});

it('discovers the current monorepo package manifests by package name', function (): void {
    $manifests = new ManifestLoader(new ManifestValidator)->discover();

    expect($manifests)
        ->toHaveKeys([
            'capell-app/admin',
            'capell-app/core',
            'capell-app/frontend',
            'capell-app/installer',
            'capell-app/marketplace',
        ])
        ->and($manifests['capell-app/core']->manifestVersion)->toBe(3)
        ->and($manifests['capell-app/core']->installPath)->toBe(realpath(dirname(__DIR__, 3)));
});

it('rejects legacy manifest v2 capell.json files on direct load', function (): void {
    $path = makeManifestFile([
        'manifest-version' => 2,
        'name' => 'vendor/package',
        'kind' => 'package',
        'capell-version' => '^1.0',
        'surfaces' => ['admin'],
    ]);

    expect(fn (): CapellManifestData => new ManifestLoader(new ManifestValidator)->load($path))
        ->toThrow(InvalidManifestException::class, 'manifest-version 3');
});

it('throws when the file does not exist', function (): void {
    $loader = new ManifestLoader(new ManifestValidator);

    expect(fn (): CapellManifestData => $loader->load('/nonexistent/path/capell.json'))
        ->toThrow(InvalidManifestException::class, 'not found at');
});

it('skips discovered manifests that disappear during discovery', function (): void {
    $loader = new ManifestLoader(new ManifestValidator);
    $method = new ReflectionMethod($loader, 'loadDiscoveredManifest');

    $manifest = $method->invoke(
        $loader,
        '/nonexistent/path/capell.json',
        'vendor/package',
        'test discovery source',
    );

    expect($manifest)->toBeNull();
});

it('throws when the manifest is invalid JSON', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-manifest-') . '.json';
    file_put_contents($path, '{ not valid json }');

    $loader = new ManifestLoader(new ManifestValidator);

    expect(fn (): CapellManifestData => $loader->load($path))
        ->toThrow(InvalidManifestException::class);

    unlink($path);
});

it('does not discover path repository packages only because their namespace is autoloaded', function (): void {
    $loader = new ManifestLoader(new ManifestValidator);
    $method = new ReflectionMethod($loader, 'shouldDiscoverPathRepository');

    expect($method->invoke($loader, 'capell-app/login-audit', ['capell-app/admin']))
        ->toBeFalse()
        ->and($method->invoke($loader, 'capell-app/login-audit', ['capell-app/login-audit']))
        ->toBeTrue()
        ->and($method->invoke($loader, '', ['capell-app/login-audit']))
        ->toBeFalse();
});

it('normalises composer metadata used during manifest discovery', function (): void {
    $directory = sys_get_temp_dir() . '/capell-manifest-paths-' . bin2hex(random_bytes(6));
    mkdir($directory . '/src', recursive: true);
    mkdir($directory . '/tests', recursive: true);

    $loader = new ManifestLoader(new ManifestValidator);
    $psr4Paths = new ReflectionMethod($loader, 'composerPsr4Paths');
    $psr4Prefixes = new ReflectionMethod($loader, 'composerPsr4Prefixes');
    $requiredPackages = new ReflectionMethod($loader, 'requiredPackageNames');

    expect($psr4Paths->invoke($loader, $directory, 'src'))->toBe([realpath($directory . '/src')])
        ->and($psr4Paths->invoke($loader, $directory, ['src', '', 123, 'missing']))->toBe([realpath($directory . '/src')])
        ->and($psr4Prefixes->invoke($loader, $directory, [
            'Vendor\\Package\\' => ['src', 'tests'],
            '' => 'src',
            123 => 'src',
            'Vendor\\Missing\\' => 'missing',
        ]))->toBe([
            'Vendor\\Package\\' => [
                realpath($directory . '/src'),
                realpath($directory . '/tests'),
            ],
        ])
        ->and($requiredPackages->invoke($loader, [
            'require' => ['capell-app/admin' => '^1.0'],
            'require-dev' => ['capell-app/frontend' => '^1.0'],
        ]))->toBe(['capell-app/admin', 'capell-app/frontend']);
});

it('skips legacy or unreadable discovered manifest payloads without treating them as current packages', function (): void {
    $legacyPath = makeManifestFile([
        'manifest-version' => 2,
        'name' => 'vendor/legacy',
        'kind' => 'package',
        'capell-version' => '^1.0',
        'surfaces' => ['admin'],
    ]);

    $invalidJsonPath = tempnam(sys_get_temp_dir(), 'capell-legacy-check-') . '.json';
    file_put_contents($invalidJsonPath, 'not-json');

    $loader = new ManifestLoader(new ManifestValidator);
    $legacyMethod = new ReflectionMethod($loader, 'isLegacyManifest');
    $loadDiscoveredMethod = new ReflectionMethod($loader, 'loadDiscoveredManifest');

    expect($legacyMethod->invoke($loader, '/missing/capell.json'))->toBeFalse()
        ->and($legacyMethod->invoke($loader, $invalidJsonPath))->toBeFalse()
        ->and($legacyMethod->invoke($loader, $legacyPath))->toBeTrue()
        ->and($loadDiscoveredMethod->invoke($loader, $legacyPath, 'vendor/legacy', 'legacy test'))->toBeNull();
});

it('loads package-local psr-4 classes before validating manifest classes', function (): void {
    $directory = sys_get_temp_dir() . '/capell-manifest-local-autoload-' . bin2hex(random_bytes(6));
    $providerDirectory = $directory . '/src/Providers';

    mkdir($providerDirectory, recursive: true);

    file_put_contents(
        $providerDirectory . '/PackageServiceProvider.php',
        <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Vendor\LocalAutoload\Providers;

        use Illuminate\Support\ServiceProvider;

        final class PackageServiceProvider extends ServiceProvider
        {
        }
        PHP,
    );

    file_put_contents(
        $directory . '/composer.json',
        json_encode([
            'name' => 'vendor/local-autoload-package',
            'autoload' => [
                'psr-4' => [
                    'Vendor\\LocalAutoload\\' => 'src/',
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    );

    file_put_contents(
        $directory . '/capell.json',
        json_encode(capellManifestV3Array(
            name: 'vendor/local-autoload-package',
            providers: [
                'runtime' => ['Vendor\\LocalAutoload\\Providers\\PackageServiceProvider'],
            ],
        ), JSON_THROW_ON_ERROR),
    );

    expect(class_exists('Vendor\\LocalAutoload\\Providers\\PackageServiceProvider'))->toBeFalse();

    $manifest = new ManifestLoader(new ManifestValidator)->load($directory . '/capell.json');

    expect($manifest->name)->toBe('vendor/local-autoload-package')
        ->and(class_exists('Vendor\\LocalAutoload\\Providers\\PackageServiceProvider'))->toBeTrue();
});

it('registers manifest autoloaders without adding Composer vendor directories', function (): void {
    $vendorDirectories = array_keys(ClassLoader::getRegisteredLoaders());
    $loader = new ManifestLoader(new ManifestValidator);
    $classes = [];

    foreach (['first', 'second'] as $suffix) {
        $directory = sys_get_temp_dir() . '/capell-manifest-shared-autoload-' . $suffix . '-' . bin2hex(random_bytes(6));
        $namespace = 'Vendor\\SharedAutoload' . ucfirst($suffix);
        $class = $namespace . '\\Providers\\PackageServiceProvider';
        $classes[] = $class;

        mkdir($directory . '/src/Providers', recursive: true);
        file_put_contents(
            $directory . '/src/Providers/PackageServiceProvider.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace}\\Providers;\n\nfinal class PackageServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n}\n",
        );
        file_put_contents($directory . '/composer.json', json_encode([
            'name' => 'vendor/shared-autoload-' . $suffix,
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/capell.json', json_encode(capellManifestV3Array(
            name: 'vendor/shared-autoload-' . $suffix,
            providers: ['runtime' => [$class]],
        ), JSON_THROW_ON_ERROR));

        expect(class_exists($class))->toBeFalse();

        $loader->load($directory . '/capell.json');
    }

    // Composer's InstalledVersions stats "<vendor dir>/composer/installed.php"
    // for every registered loader on every version lookup, so manifest
    // directories must never be registered as vendor directories.
    expect(array_keys(ClassLoader::getRegisteredLoaders()))->toBe($vendorDirectories);

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue();
    }
});

function manifestV3LoaderFixture(): array
{
    return capellManifestV3Array();
}

function manifestV3LoaderComposerJson(): array
{
    return [
        'name' => 'vendor/package',
        'autoload' => [
            'psr-4' => [
                'Vendor\\Package\\' => 'src/',
            ],
        ],
    ];
}
