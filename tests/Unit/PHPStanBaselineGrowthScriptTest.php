<?php

declare(strict_types=1);

it('rejects baseline growth across supported ignore-error entry forms', function (): void {
    $root = sys_get_temp_dir() . '/capell-phpstan-baseline-growth-' . bin2hex(random_bytes(8));
    $phpstan = $root . '/phpstan';

    mkdir($phpstan, 0777, true);
    copy(dirname(__DIR__, 2) . '/scripts/check-phpstan-baseline-growth.sh', $root . '/check-phpstan-baseline-growth.sh');

    try {
        phpstanBaselineGit($root, ['init', '--quiet']);
        phpstanBaselineGit($root, ['config', 'user.email', 'tests@capell.dev']);
        phpstanBaselineGit($root, ['config', 'user.name', 'Capell Tests']);

        file_put_contents($phpstan . '/ignore-errors.neon', <<<'NEON'
parameters:
    ignoreErrors:
        - identifier: first.unmatched
        -
            message: '#Counted failures#'
            count: 2
        - '#Legacy scalar failure#'
NEON);
        phpstanBaselineGit($root, ['add', 'phpstan/ignore-errors.neon']);
        phpstanBaselineGit($root, ['commit', '--quiet', '-m', 'Base']);

        file_put_contents($phpstan . '/ignore-errors.neon', <<<'NEON'

        - message: '#New failure#'
NEON
            , FILE_APPEND);
        phpstanBaselineGit($root, ['add', 'phpstan/ignore-errors.neon']);
        phpstanBaselineGit($root, ['commit', '--quiet', '-m', 'Growth']);

        [$exitCode, $output] = phpstanBaselineRun($root);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('PHPStan baseline debt: current=5, base=4')
            ->and($output)->toContain('PHPStan baseline grew by 1 ignored error(s).');
    } finally {
        phpstanBaselineDeleteDirectory($root);
    }
});

/**
 * @param  list<string>  $arguments
 */
function phpstanBaselineGit(string $root, array $arguments): void
{
    $command = implode(' ', [
        'git',
        '-C',
        escapeshellarg($root),
        ...array_map(escapeshellarg(...), $arguments),
        '2>&1',
    ]);

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0, implode("\n", $output));
}

/**
 * @return array{int, string}
 */
function phpstanBaselineRun(string $root): array
{
    $command = implode(' ', [
        'cd',
        escapeshellarg($root),
        '&&',
        'bash',
        'check-phpstan-baseline-growth.sh',
        '2>&1',
    ]);

    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

function phpstanBaselineDeleteDirectory(string $path): void
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
