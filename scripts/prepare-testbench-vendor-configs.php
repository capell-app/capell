<?php

declare(strict_types=1);

function copyTestbenchFile(string $sourcePath, string $targetPath, string $type): void
{
    if (is_file($targetPath) && hash_file('sha256', $sourcePath) === hash_file('sha256', $targetPath)) {
        return;
    }

    $targetDirectory = dirname($targetPath);

    if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
        throw new RuntimeException("Unable to create target directory: {$targetDirectory}");
    }

    if (! copy($sourcePath, $targetPath)) {
        throw new RuntimeException("Unable to copy {$type} to: {$targetPath}");
    }
}

$configPaths = [
    'spatie/laravel-permission/config/permission.php',
    'spatie/laravel-settings/config/settings.php',
    'bezhansalleh/filament-shield/config/filament-shield.php',
];

$assetDirectories = [
    'packages/frontend/publishes/build' => 'public/vendor/capell-frontend',
];

foreach ($configPaths as $relativePath) {
    $sourcePath = dirname(__DIR__) . '/vendor/' . $relativePath;
    $targetPath = dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/vendor/' . $relativePath;

    if (! is_file($sourcePath)) {
        throw new RuntimeException("Missing source config: {$sourcePath}");
    }

    copyTestbenchFile($sourcePath, $targetPath, 'config');
}

foreach ($assetDirectories as $sourceDirectory => $targetDirectory) {
    $sourcePath = dirname(__DIR__) . '/' . $sourceDirectory;
    $targetPath = dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/' . $targetDirectory;

    if (! is_dir($sourcePath)) {
        throw new RuntimeException("Missing source asset directory: {$sourcePath}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $asset) {
        $relativePath = $iterator->getSubPathname();
        $destination = $targetPath . '/' . $relativePath;

        if ($asset->isDir()) {
            if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
                throw new RuntimeException("Unable to create target directory: {$destination}");
            }

            continue;
        }

        copyTestbenchFile($asset->getPathname(), $destination, 'asset');
    }
}
