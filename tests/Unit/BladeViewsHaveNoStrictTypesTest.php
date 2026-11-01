<?php

declare(strict_types=1);

// Livewire Blaze 1.0.20 puts a version marker before the compiled view, so a view that opens with
// declare(strict_types=1) fatals ("must be the very first statement"). Blade views stay without it.
it('keeps declare(strict_types=1) out of Blade views', function (): void {
    $root = dirname(__DIR__, 2);
    $views = 0;
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages', FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = (string) $file;

        if (! str_ends_with($path, '.blade.php')) {
            continue;
        }

        if (! str_contains($path, '/resources/views/')) {
            continue;
        }

        $views++;

        if (preg_match('/\A<\?php\s+declare\(strict_types=1\);/', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace($root . '/', '', $path);
        }
    }

    expect($views)->toBeGreaterThan(0)->and($offenders)->toBe([]);
});
