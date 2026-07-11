<?php

declare(strict_types=1);

namespace Capell\Core\Support\Composer;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use ReflectionMethod;

final class ComposerAutoloaderReloader
{
    public static function reload(?string $vendorPath = null): void
    {
        $vendorPath ??= base_path('vendor');
        $composerPath = rtrim($vendorPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer';

        self::reloadInstalledVersions($composerPath);
        self::registerFreshClassLoader($vendorPath, $composerPath);
    }

    private static function reloadInstalledVersions(string $composerPath): void
    {
        $installedPath = $composerPath . DIRECTORY_SEPARATOR . 'installed.php';

        if (! file_exists($installedPath)) {
            return;
        }

        $installed = include $installedPath;

        if (is_array($installed)) {
            new ReflectionMethod(InstalledVersions::class, 'reload')->invoke(null, $installed);
        }
    }

    private static function registerFreshClassLoader(string $vendorPath, string $composerPath): void
    {
        if (! is_dir($composerPath)) {
            return;
        }

        $normalizedVendorPath = realpath($vendorPath) ?: $vendorPath;

        self::unregisterExistingLoader($normalizedVendorPath);

        $loader = new ClassLoader($normalizedVendorPath);

        self::loadPsr0($loader, $composerPath);
        self::loadPsr4($loader, $composerPath);
        self::loadClassMap($loader, $composerPath);

        $loader->register(prepend: true);
        self::loadFiles($composerPath);
    }

    private static function unregisterExistingLoader(string $vendorPath): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $registeredVendorPath => $loader) {
            if ($registeredVendorPath === $vendorPath) {
                $loader->unregister();
            }
        }
    }

    private static function loadPsr0(ClassLoader $loader, string $composerPath): void
    {
        $namespacesPath = $composerPath . DIRECTORY_SEPARATOR . 'autoload_namespaces.php';

        if (! file_exists($namespacesPath)) {
            return;
        }

        $namespaces = include $namespacesPath;

        if (! is_array($namespaces)) {
            return;
        }

        foreach ($namespaces as $prefix => $paths) {
            $loader->set((string) $prefix, $paths);
        }
    }

    private static function loadPsr4(ClassLoader $loader, string $composerPath): void
    {
        $psr4Path = $composerPath . DIRECTORY_SEPARATOR . 'autoload_psr4.php';

        if (! file_exists($psr4Path)) {
            return;
        }

        $prefixes = include $psr4Path;

        if (! is_array($prefixes)) {
            return;
        }

        foreach ($prefixes as $prefix => $paths) {
            $loader->setPsr4((string) $prefix, $paths);
        }
    }

    private static function loadClassMap(ClassLoader $loader, string $composerPath): void
    {
        $classMapPath = $composerPath . DIRECTORY_SEPARATOR . 'autoload_classmap.php';

        if (! file_exists($classMapPath)) {
            return;
        }

        $classMap = include $classMapPath;

        if (is_array($classMap)) {
            $loader->addClassMap($classMap);
        }
    }

    private static function loadFiles(string $composerPath): void
    {
        $filesPath = $composerPath . DIRECTORY_SEPARATOR . 'autoload_files.php';

        if (! file_exists($filesPath)) {
            return;
        }

        $files = include $filesPath;

        if (! is_array($files)) {
            return;
        }

        foreach ($files as $identifier => $file) {
            if (! is_string($file)) {
                continue;
            }

            if (isset($GLOBALS['__composer_autoload_files'][$identifier])) {
                continue;
            }

            $GLOBALS['__composer_autoload_files'][$identifier] = true;

            require $file;
        }
    }
}
