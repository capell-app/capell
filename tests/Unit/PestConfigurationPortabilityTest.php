<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('discovers package tests with their package group on this filesystem', function (string $package): void {
    $root = dirname(__DIR__, 2);
    $directory = $root . '/packages/' . $package . '/tests';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $test = null;

    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with((string) $file->getFilename(), 'Test.php')) {
            $test = $file->getPathname();
            break;
        }
    }

    expect($test)->not->toBeNull();
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', $test, '--configuration=phpunit.xml', '--list-groups'], $root);

    // Duplicate case-only registrations break discovery on case-sensitive hosts.
    expect($process->run())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toMatch('/^\\s*- ' . preg_quote($package, '/') . '(?: \(.*\))?\.?$/m');
})->with(['core', 'admin', 'frontend', 'installer', 'marketplace']);
