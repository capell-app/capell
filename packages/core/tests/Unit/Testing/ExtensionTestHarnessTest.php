<?php

declare(strict_types=1);

use Capell\Core\Testing\ExtensionTestHarness;

it('exposes reusable extension contract assertions and summary data', function (): void {
    $directory = sys_get_temp_dir() . '/capell-extension-harness-' . bin2hex(random_bytes(6));

    mkdir($directory . '/src/Providers', 0755, true);
    mkdir($directory . '/src/Routes', 0755, true);

    file_put_contents($directory . '/composer.json', json_encode([
        'name' => 'vendor/harness',
        'autoload' => [
            'psr-4' => [
                'Vendor\\Harness\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($directory . '/src/Providers/PackageServiceProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\Harness\Providers;

use Illuminate\Support\ServiceProvider;

final class PackageServiceProvider extends ServiceProvider
{
}
PHP);

    file_put_contents($directory . '/src/Routes/HarnessRoutes.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\Harness\Routes;

use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;

final class HarnessRoutes implements RegistersExtensionRoute
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
PHP);

    file_put_contents($directory . '/capell.json', json_encode(
        capellManifestV3Array(
            name: 'vendor/harness',
            surfaces: ['frontend'],
            namespace: 'Vendor\\Harness',
            providers: [
                'runtime' => ['Vendor\\Harness\\Providers\\PackageServiceProvider'],
            ],
            overrides: [
                'contributes' => [
                    [
                        'type' => 'route',
                        'class' => 'Vendor\\Harness\\Routes\\HarnessRoutes',
                        'surface' => 'frontend',
                        'namePrefix' => 'vendor.harness',
                    ],
                ],
                'database' => [
                    'migrations' => false,
                    'settings' => false,
                    'requiredTables' => [],
                ],
            ],
        ),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));

    $harness = ExtensionTestHarness::forPath($directory);

    $harness
        ->assertManifestValid()
        ->assertContributionRegistered('route', 'Vendor\\Harness\\Routes\\HarnessRoutes')
        ->assertRoutesOwnedByPackage()
        ->assertScheduledJobsRegistered()
        ->assertNoUnsafePublicCache();

    expect($harness->summary())
        ->toMatchArray([
            'package' => 'vendor/harness',
            'routes' => 1,
            'migrations' => false,
            'settings' => 0,
            'scheduledJobs' => 0,
            'contributions' => 1,
        ]);
});

it('resolves manifests from direct files package paths and package directories', function (): void {
    $directory = makeExtensionHarnessPackage(
        'vendor/harness-file',
        'Vendor\\HarnessFile',
        [
            [
                'type' => 'route',
                'class' => 'Vendor\\HarnessFile\\Routes\\HarnessRoutes',
                'surface' => 'frontend',
                'namePrefix' => 'vendor.harness-file',
            ],
        ],
    );

    $fromDirectory = ExtensionTestHarness::forPackageOrPath($directory);
    $fromFile = ExtensionTestHarness::forPath($directory . '/capell.json');

    expect($fromDirectory->summary()['package'])->toBe('vendor/harness-file')
        ->and($fromFile->summary()['package'])->toBe('vendor/harness-file');

    expect(fn (): ExtensionTestHarness => ExtensionTestHarness::forPath($directory . '/missing'))
        ->toThrow(RuntimeException::class, 'No capell.json manifest found');
});

it('fails clearly when route and scheduled job contributions are missing ownership metadata', function (): void {
    $routeDirectory = makeExtensionHarnessPackage(
        'vendor/harness-route-failure',
        'Vendor\\HarnessRouteFailure',
        [
            [
                'type' => 'route',
                'class' => 'Vendor\\HarnessRouteFailure\\Routes\\HarnessRoutes',
                'surface' => 'frontend',
            ],
        ],
    );

    $routeHarness = ExtensionTestHarness::forPath($routeDirectory);

    expect(fn (): ExtensionTestHarness => $routeHarness->assertContributionRegistered('route', 'Vendor\\HarnessRouteFailure\\Routes\\MissingRoutes'))
        ->toThrow(AssertionError::class, 'is not declared')
        ->and(fn (): ExtensionTestHarness => $routeHarness->assertRoutesOwnedByPackage())
        ->toThrow(AssertionError::class, 'must declare a namePrefix');

    $jobDirectory = makeExtensionHarnessPackage(
        'vendor/harness-job-failure',
        'Vendor\\HarnessJobFailure',
        [
            [
                'type' => 'scheduled-job',
                'class' => 'Vendor\\HarnessJobFailure\\Jobs\\HarnessJob',
                'surface' => 'console',
            ],
        ],
    );

    expect(fn (): ExtensionTestHarness => ExtensionTestHarness::forPath($jobDirectory)->assertScheduledJobsRegistered())
        ->toThrow(AssertionError::class, 'must declare a schedule');
});

it('asserts theme manifests and safe theme asset urls', function (): void {
    $directory = makeExtensionHarnessPackage('vendor/theme-harness', 'Vendor\\ThemeHarness', []);

    mkdir($directory . '/resources/views', 0755, true);
    file_put_contents($directory . '/resources/views/page.blade.php', '<link href="@frontendAsset(\'vendor/theme-harness/theme.css\')" rel="stylesheet">');

    $manifest = capellManifestV3Array(
        name: 'vendor/theme-harness',
        surfaces: ['frontend', 'shared'],
        namespace: 'Vendor\\ThemeHarness',
        providers: ['runtime' => ['Vendor\\ThemeHarness\\Providers\\PackageServiceProvider']],
        overrides: [
            'kind' => 'theme',
            'themeKey' => 'harness',
            'extends' => 'default',
            'visibility' => 'support',
            'security' => [
                'riskTier' => 'low',
                'publicSurface' => ['auth' => 'public'],
                'sensitiveData' => [],
                'publicOutput' => [
                    'cacheSafe' => true,
                    'forbidAuthoringSurface' => true,
                    'forbidSecrets' => true,
                    'forbidPublicBladeQueries' => true,
                ],
                'externalHttpClients' => ['clients' => []],
                'adminSurface' => ['authorization' => 'none'],
            ],
        ],
    );

    file_put_contents($directory . '/capell.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    ExtensionTestHarness::forPath($directory)
        ->assertManifestValid()
        ->assertThemeManifest('harness')
        ->assertThemeUsesSafeAssetUrls();

    file_put_contents($directory . '/resources/views/page.blade.php', '<img src="/images/logo.png">');

    expect(fn (): ExtensionTestHarness => ExtensionTestHarness::forPath($directory)->assertThemeUsesSafeAssetUrls())
        ->toThrow(AssertionError::class, 'root-relative asset URLs');
});

/**
 * @param  list<array<string, mixed>>  $contributions
 */
function makeExtensionHarnessPackage(string $name, string $namespace, array $contributions): string
{
    $directory = sys_get_temp_dir() . '/' . str_replace('/', '-', $name) . '-' . bin2hex(random_bytes(6));
    $sourceDirectory = $directory . '/src';

    mkdir($sourceDirectory . '/Providers', 0755, true);
    mkdir($sourceDirectory . '/Routes', 0755, true);
    mkdir($sourceDirectory . '/Jobs', 0755, true);

    file_put_contents($directory . '/composer.json', json_encode([
        'name' => $name,
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($sourceDirectory . '/Providers/PackageServiceProvider.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Providers;

use Illuminate\\Support\\ServiceProvider;

final class PackageServiceProvider extends ServiceProvider
{
}
PHP);

    file_put_contents($sourceDirectory . '/Routes/HarnessRoutes.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Routes;

use Capell\\Core\\Contracts\\Extensions\\RegistersExtensionRoute;

final class HarnessRoutes implements RegistersExtensionRoute
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
PHP);

    file_put_contents($sourceDirectory . '/Jobs/HarnessJob.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Jobs;

use Capell\\Core\\Contracts\\Extensions\\RunsScheduledExtensionJob;

final class HarnessJob implements RunsScheduledExtensionJob
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
PHP);

    file_put_contents($directory . '/capell.json', json_encode(
        capellManifestV3Array(
            name: $name,
            surfaces: ['frontend', 'console'],
            namespace: $namespace,
            providers: [
                'runtime' => [$namespace . '\\Providers\\PackageServiceProvider'],
            ],
            overrides: [
                'contributes' => $contributions,
                'database' => [
                    'migrations' => false,
                    'settings' => false,
                    'requiredTables' => [],
                ],
            ],
        ),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));

    return $directory;
}
