<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('discovers package tests with their package group on this filesystem', function (string $package): void {
    $root = dirname(__DIR__, 2);
    $directory = $root . '/packages/' . $package . '/tests';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $test = null;

    foreach ($files as $file) {
        // Fixture classes can also end in Test.php; only a file that declares tests lists a group.
        if ($file->isFile()
            && str_ends_with((string) $file->getFilename(), 'Test.php')
            && preg_match('/^\s*(?:it|test|describe)\(/m', (string) file_get_contents($file->getPathname())) === 1) {
            $test = $file->getPathname();
            break;
        }
    }

    expect($test)->not->toBeNull();
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', $test, '--configuration=phpunit.xml', '--list-groups'], $root);
    // Hang ceiling only: a busy host must slow this down, never fail it.
    $process->setTimeout(300);

    // Duplicate case-only registrations break discovery on case-sensitive hosts.
    expect($process->run())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toMatch('/^\\s*- ' . preg_quote($package, '/') . '(?: \(.*\))?\.?$/m');
})->with(['core', 'admin', 'frontend', 'installer', 'marketplace']);
