<?php

declare(strict_types=1);

$viewsDirectory = dirname(__DIR__) . '/storage/framework/views';

if (! is_dir($viewsDirectory)) {
    return;
}

$viewDirectories = glob($viewsDirectory . '/phpunit-*', GLOB_ONLYDIR);

if ($viewDirectories === false) {
    $viewDirectories = [];
}

try {
    foreach ($viewDirectories as $viewDirectory) {
        removeDirectory($viewDirectory);
    }
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, $runtimeException->getMessage() . PHP_EOL);

    return 1;
}

function removeDirectory(string $directory): void
{
    if (is_link($directory)) {
        unlinkPath($directory);

        return;
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        if (! is_dir($directory)) {
            return;
        }

        removeDirectoryContents($directory);

        if (! is_dir($directory) || @rmdir($directory)) {
            return;
        }

        usleep(100000);
    }

    if (is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to remove PHPUnit view cache directory [%s].', $directory));
    }
}

function removeDirectoryContents(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);

    if ($entries === false) {
        if (! is_dir($directory)) {
            return;
        }

        throw new RuntimeException(sprintf('Unable to scan PHPUnit view cache directory [%s].', $directory));
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($path) && ! is_link($path)) {
            removeDirectory($path);

            continue;
        }

        if (file_exists($path) || is_link($path)) {
            unlinkPath($path);
        }
    }
}

function unlinkPath(string $path): void
{
    if (unlink($path)) {
        return;
    }

    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    throw new RuntimeException(sprintf('Unable to remove PHPUnit view cache path [%s].', $path));
}
