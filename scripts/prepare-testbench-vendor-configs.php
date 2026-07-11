<?php

declare(strict_types=1);

$configPaths = [
    'spatie/laravel-permission/config/permission.php',
    'spatie/laravel-settings/config/settings.php',
    'bezhansalleh/filament-shield/config/filament-shield.php',
];

foreach ($configPaths as $relativePath) {
    $sourcePath = dirname(__DIR__) . '/vendor/' . $relativePath;
    $targetPath = dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/vendor/' . $relativePath;

    if (! is_file($sourcePath)) {
        throw new RuntimeException("Missing source config: {$sourcePath}");
    }

    $targetDirectory = dirname($targetPath);

    if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
        throw new RuntimeException("Unable to create target directory: {$targetDirectory}");
    }

    if (! copy($sourcePath, $targetPath)) {
        throw new RuntimeException("Unable to copy config to: {$targetPath}");
    }
}
