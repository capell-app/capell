<?php

declare(strict_types=1);

$shards = max(1, (int) ($_SERVER['PEST_SHARDS'] ?? getenv('PEST_SHARDS') ?: 10));
$phpBinary = PHP_BINARY;
$configuration = 'phpunit.xml';
$junitDirectory = (string) (getenv('PEST_SHARDS_JUNIT_DIR') ?: '');

if ($junitDirectory !== '' && ! is_dir($junitDirectory) && ! mkdir($junitDirectory, 0o777, true) && ! is_dir($junitDirectory)) {
    fwrite(STDERR, "Unable to create JUnit report directory [{$junitDirectory}]." . PHP_EOL);

    exit(1);
}

try {
    $files = testFiles();
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);

    exit(1);
}

$timings = shardTimings();
$partitions = partitionFiles($files, $timings, $shards);
$partitionWeights = partitionWeights($partitions, $timings);
$processes = [];
$exitCode = 0;
$startedAt = microtime(true);

if (count(array_unique(array_values($timings))) <= 1) {
    echo '[pest-shards] Timing manifest is unweighted; run composer test:profile for slow-file evidence.' . PHP_EOL;
}

foreach ($partitions as $index => $partitionFiles) {
    $shard = $index + 1;

    if ($partitionFiles === []) {
        continue;
    }

    $command = [
        $phpBinary,
        '-d',
        'memory_limit=1536M',
        '-d',
        'max_execution_time=0',
        '-d',
        'pcov.enabled=0',
        'vendor/bin/pest',
        ...$partitionFiles,
        '--colors=always',
        '--compact',
        '--stop-on-error',
        '--stop-on-failure',
        "--configuration={$configuration}",
    ];

    // Opt-in per-shard JUnit reports. scripts/update-pest-shard-timings.php sets this
    // to harvest real per-file wall times; a normal shard run writes no reports.
    if ($junitDirectory !== '') {
        $command[] = sprintf('--log-junit=%s/shard-%d.xml', $junitDirectory, $shard);
    }

    $environment = getenv();

    if (! is_array($environment)) {
        $environment = [];
    }

    $environment['TEST_TOKEN'] = sprintf('shard-%d', $shard);

    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        env_vars: $environment,
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "Unable to start Pest shard {$shard}/{$shards}." . PHP_EOL);

        exit(1);
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $processes[$shard] = [
        'process' => $process,
        'pipes' => $pipes,
        'started_at' => microtime(true),
        'files' => count($partitionFiles),
        'weight' => $partitionWeights[$index] ?? 0.0,
    ];

    printf(
        "[shard %d] Running %d test files; estimated weight %.2f.\n",
        $shard,
        count($partitionFiles),
        $partitionWeights[$index] ?? 0.0,
    );
}

while ($processes !== []) {
    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);

        if ($stdout !== false && $stdout !== '') {
            echo "[shard {$index}] {$stdout}";
        }

        if ($stderr !== false && $stderr !== '') {
            fwrite(STDERR, "[shard {$index}] {$stderr}");
        }

        $status = proc_get_status($process['process']);

        if ($status['running']) {
            continue;
        }

        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);

        $code = proc_close($process['process']);

        if ($code === -1 && isset($status['exitcode']) && $status['exitcode'] !== -1) {
            $code = $status['exitcode'];
        }

        if ($code !== 0) {
            $exitCode = $code;
        }

        printf(
            "[shard %d] Finished in %.2fs with exit code %d.\n",
            $index,
            microtime(true) - $process['started_at'],
            $code,
        );

        unset($processes[$index]);
    }

    usleep(100_000);
}

printf("[pest-shards] Finished %d shards in %.2fs.\n", $shards, microtime(true) - $startedAt);

// A top-level `return` does not set the process exit status, so a failing shard used to be
// reported as a passing run. Exit explicitly.
exit($exitCode);

/**
 * @return list<string>
 */
function testFiles(): array
{
    $files = [];

    foreach (['tests', 'packages'] as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * @return array<string, float>
 */
function shardTimings(): array
{
    $path = 'tests/.pest/shards.json';

    if (! is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        return [];
    }

    $data = json_decode($contents, true);

    if (! is_array($data) || ! isset($data['timings']) || ! is_array($data['timings'])) {
        return [];
    }

    $timings = [];

    foreach ($data['timings'] as $identifier => $timing) {
        if (! is_string($identifier) || (! is_int($timing) && ! is_float($timing))) {
            continue;
        }

        $timings[$identifier] = (float) $timing;
    }

    return $timings;
}

/**
 * @param  list<string>  $files
 * @param  array<string, float>  $timings
 * @return list<list<string>>
 */
function partitionFiles(array $files, array $timings, int $shards): array
{
    $filesWithTimings = array_map(
        static fn (string $file): array => ['file' => $file, 'time' => resolveShardTiming($file, $timings)],
        $files,
    );

    usort($filesWithTimings, static fn (array $left, array $right): int => $right['time'] <=> $left['time']);

    $partitions = array_fill(0, $shards, []);
    $partitionTimes = array_fill(0, $shards, 0.0);

    foreach ($filesWithTimings as $fileWithTiming) {
        $partition = array_search(min($partitionTimes), $partitionTimes, strict: true);
        assert(is_int($partition));

        $partitions[$partition][] = $fileWithTiming['file'];
        $partitionTimes[$partition] += $fileWithTiming['time'];
    }

    return $partitions;
}

/**
 * @param  array<string, float>  $timings
 */
function resolveShardTiming(string $file, array $timings): float
{
    if (isset($timings[$file])) {
        return (float) $timings[$file];
    }

    $normalizedPath = str_replace(['/', '.php'], ['\\', ''], ltrim($file, './'));
    $packageTestIdentifier = packageTestIdentifier($normalizedPath);

    foreach ([
        $normalizedPath,
        ucfirst($normalizedPath),
        $packageTestIdentifier,
        packageNamespaceTestIdentifier($normalizedPath),
    ] as $candidate) {
        if ($candidate !== null && isset($timings[$candidate])) {
            return (float) $timings[$candidate];
        }
    }

    return 1.0;
}

function packageTestIdentifier(string $normalizedPath): ?string
{
    if (! str_starts_with($normalizedPath, 'packages\\')) {
        return null;
    }

    $segments = explode('\\', $normalizedPath);

    if (count($segments) < 4 || $segments[2] !== 'tests') {
        return null;
    }

    return 'Packages\\' . $segments[1] . '\\tests\\' . implode('\\', array_slice($segments, 3));
}

function packageNamespaceTestIdentifier(string $normalizedPath): ?string
{
    if (! str_starts_with($normalizedPath, 'packages\\')) {
        return null;
    }

    $segments = explode('\\', $normalizedPath);

    if (count($segments) < 4 || $segments[2] !== 'tests') {
        return null;
    }

    $packageNamespace = match ($segments[1]) {
        'admin' => 'Capell\\Admin\\Tests',
        'core' => 'Capell\\Core\\Tests',
        'frontend' => 'Capell\\Frontend\\Tests',
        'installer' => 'Capell\\Installer\\Tests',
        'marketplace' => 'Capell\\Marketplace\\Tests',
        default => null,
    };

    if ($packageNamespace === null) {
        return null;
    }

    return $packageNamespace . '\\' . implode('\\', array_slice($segments, 3));
}

/**
 * @param  list<list<string>>  $partitions
 * @param  array<string, float>  $timings
 * @return list<float>
 */
function partitionWeights(array $partitions, array $timings): array
{
    return array_map(
        static fn (array $files): float => array_reduce(
            $files,
            static fn (float $weight, string $file): float => $weight + resolveShardTiming($file, $timings),
            0.0,
        ),
        $partitions,
    );
}
