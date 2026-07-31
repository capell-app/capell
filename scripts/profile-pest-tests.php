<?php

declare(strict_types=1);

$limit = max(1, (int) ($_SERVER['PEST_PROFILE_LIMIT'] ?? getenv('PEST_PROFILE_LIMIT') ?: 40));
$paths = array_slice($argv, 1);

if ($paths === []) {
    $paths = ['tests', 'packages'];
}

$files = [];

foreach ($paths as $path) {
    if (is_file($path) && str_ends_with($path, 'Test.php')) {
        $files[] = $path;

        continue;
    }

    if (! is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        if (! str_ends_with((string) $file->getFilename(), 'Test.php')) {
            continue;
        }

        $files[] = $file->getPathname();
    }
}

sort($files);

$results = [];

foreach ($files as $file) {
    $startedAt = microtime(true);

    passthru(
        PHP_BINARY . ' vendor/bin/pest ' . escapeshellarg((string) $file) . ' --configuration=phpunit.xml --compact',
        $exitCode,
    );

    $duration = microtime(true) - $startedAt;
    $results[] = [
        'file' => $file,
        'duration' => $duration,
        'exit_code' => $exitCode,
    ];

    if ($exitCode !== 0) {
        fwrite(STDERR, sprintf('Profiling stopped because %s failed.', $file) . PHP_EOL);

        break;
    }
}

usort($results, static fn (array $left, array $right): int => $right['duration'] <=> $left['duration']);

foreach (array_slice($results, 0, $limit) as $result) {
    printf(
        "%7.2fs  %s%s\n",
        $result['duration'],
        $result['file'],
        $result['exit_code'] === 0 ? '' : ' FAILED',
    );
}
