<?php

declare(strict_types=1);

it('extracts the configured PHPStan level', function (): void {
    $fixture = phpStanMetricsFixture();

    try {
        file_put_contents($fixture['config'], "parameters:\n    level: 9\n");

        [$exitCode, $output] = phpStanMetricsRun($fixture['config']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('PHPStan level: 9');
    } finally {
        phpStanMetricsDeleteDirectory($fixture['root']);
    }
});

it('writes the PHPStan level to GitHub output', function (): void {
    $fixture = phpStanMetricsFixture();

    try {
        file_put_contents($fixture['config'], "parameters:\n    level: 8\n");

        [$exitCode, $output] = phpStanMetricsRun($fixture['config'], $fixture['githubOutput'], ['--github-output']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('PHPStan level: 8')
            ->and((string) file_get_contents($fixture['githubOutput']))->toBe("phpstan_level=8\n");
    } finally {
        phpStanMetricsDeleteDirectory($fixture['root']);
    }
});

/**
 * @return array{root: string, config: string, githubOutput: string}
 */
function phpStanMetricsFixture(): array
{
    $root = sys_get_temp_dir() . '/capell-phpstan-metrics-' . bin2hex(random_bytes(8));

    mkdir($root, 0777, true);

    return [
        'root' => $root,
        'config' => $root . '/common.neon',
        'githubOutput' => $root . '/github-output.txt',
    ];
}

/**
 * @param  list<string>  $arguments
 * @return array{int, string}
 */
function phpStanMetricsRun(string $configPath, ?string $githubOutput = null, array $arguments = []): array
{
    $environment = [
        'CAPELL_PHPSTAN_COMMON_NEON=' . escapeshellarg($configPath),
    ];

    if ($githubOutput !== null) {
        $environment[] = 'GITHUB_OUTPUT=' . escapeshellarg($githubOutput);
    }

    $command = implode(' ', [
        ...$environment,
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2) . '/scripts/extract-phpstan-level.php'),
        ...array_map(escapeshellarg(...), $arguments),
        '2>&1',
    ]);

    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

function phpStanMetricsDeleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}
