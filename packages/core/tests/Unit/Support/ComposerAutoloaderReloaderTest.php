<?php

declare(strict_types=1);

use Capell\Core\Support\Composer\ComposerAutoloaderReloader;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;

it('refreshes composer installed metadata and class loading in the current process', function (): void {
    $originalInstalled = include dirname(__DIR__, 5) . '/vendor/composer/installed.php';
    $packageToken = bin2hex(random_bytes(6));
    $class = 'Capell\\ReloadedPackage' . $packageToken . '\\Providers\\ReloadedServiceProvider';
    $packageName = 'vendor/reloaded-package-' . strtolower($packageToken);
    $vendorPath = sys_get_temp_dir() . '/capell-composer-reload-vendor-' . $packageToken;
    $composerPath = $vendorPath . '/composer';
    $sourcePath = $vendorPath . '/vendor/reloaded-package/src';
    $providerPath = $sourcePath . '/Providers';

    mkdir($composerPath, recursive: true);
    mkdir($providerPath, recursive: true);

    file_put_contents(
        $providerPath . '/ReloadedServiceProvider.php',
        <<<PHP
        <?php

        declare(strict_types=1);

        namespace Capell\\ReloadedPackage{$packageToken}\\Providers;

        use Illuminate\\Support\\ServiceProvider;

        final class ReloadedServiceProvider extends ServiceProvider
        {
        }
        PHP,
    );

    file_put_contents(
        $composerPath . '/autoload_psr4.php',
        '<?php return ' . var_export([
            'Capell\\ReloadedPackage' . $packageToken . '\\' => [$sourcePath],
        ], true) . ';',
    );

    file_put_contents($composerPath . '/autoload_namespaces.php', '<?php return [];');
    file_put_contents($composerPath . '/autoload_classmap.php', '<?php return [];');
    file_put_contents(
        $composerPath . '/installed.php',
        '<?php return ' . var_export([
            'root' => [
                'name' => 'vendor/root',
                'pretty_version' => '1.0.0',
                'version' => '1.0.0.0',
                'reference' => null,
                'type' => 'project',
                'install_path' => dirname(__DIR__),
                'aliases' => [],
                'dev' => true,
            ],
            'versions' => [
                $packageName => [
                    'pretty_version' => '1.0.0',
                    'version' => '1.0.0.0',
                    'reference' => null,
                    'type' => 'library',
                    'install_path' => $sourcePath,
                    'aliases' => [],
                    'dev_requirement' => false,
                ],
            ],
        ], true) . ';',
    );

    expect(class_exists($class))->toBeFalse();

    try {
        ComposerAutoloaderReloader::reload($vendorPath);

        expect(InstalledVersions::isInstalled($packageName))->toBeTrue()
            ->and(class_exists($class))->toBeTrue();
    } finally {
        InstalledVersions::reload($originalInstalled);

        foreach (ClassLoader::getRegisteredLoaders() as $registeredVendorPath => $loader) {
            if ($registeredVendorPath === realpath($vendorPath)) {
                $loader->unregister();
            }
        }
    }
});
