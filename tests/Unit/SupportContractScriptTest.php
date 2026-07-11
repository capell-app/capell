<?php

declare(strict_types=1);

it('passes when manifests docs docker and workflows share the supported runtime contract', function (): void {
    $fixture = supportContractFixture();

    try {
        supportContractWriteComposer($fixture['root'] . '/composer.json', 'capell-app/capell', [
            'php' => '^8.4',
            'laravel/framework' => '^12.41.1|^13.0',
        ]);
        supportContractWriteComposer($fixture['root'] . '/packages/core/composer.json', 'capell-app/core', [
            'php' => '^8.4',
            'illuminate/database' => '^12.41.1|^13.0',
            'illuminate/support' => '^12.41.1|^13.0',
        ]);
        supportContractWriteComposer($fixture['root'] . '/packages/installer/composer.json', 'capell-app/installer', [
            'php' => '^8.4',
            'capell-app/core' => 'self.version',
        ]);
        supportContractWriteEnvironmentFiles($fixture['root']);

        [$exitCode, $output] = supportContractRun($fixture['root']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Capell support contract is aligned');
    } finally {
        supportContractDeleteDirectory($fixture['root']);
    }
});

it('reports support contract drift', function (): void {
    $fixture = supportContractFixture();

    try {
        supportContractWriteComposer($fixture['root'] . '/composer.json', 'capell-app/capell', [
            'php' => '^8.3',
            'laravel/framework' => '^12.0',
        ]);
        supportContractWriteEnvironmentFiles($fixture['root'], dockerPhpVersion: '8.3');

        [$exitCode, $output] = supportContractRun($fixture['root']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Capell support contract is out of sync')
            ->and($output)->toContain('capell-app/capell must require php ^8.4')
            ->and($output)->toContain('capell-app/capell must require laravel/framework ^12.41.1|^13.0')
            ->and($output)->toContain('.docker/Dockerfile must install PHP 8.4 packages');
    } finally {
        supportContractDeleteDirectory($fixture['root']);
    }
});

/**
 * @return array{root: string}
 */
function supportContractFixture(): array
{
    $root = sys_get_temp_dir() . '/capell-support-contract-' . bin2hex(random_bytes(8));

    mkdir($root . '/packages/core', 0777, true);
    mkdir($root . '/packages/installer', 0777, true);

    return ['root' => $root];
}

/**
 * @param  array<string, string>  $requires
 */
function supportContractWriteComposer(string $path, string $name, array $requires): void
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($path, json_encode([
        'name' => $name,
        'require' => $requires,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}

function supportContractWriteEnvironmentFiles(string $root, string $dockerPhpVersion = '8.4'): void
{
    mkdir($root . '/docs/getting-started', 0777, true);
    mkdir($root . '/.docker', 0777, true);
    mkdir($root . '/.github/workflows', 0777, true);

    $installDocs = implode("\n", [
        '| Runtime | Requirement |',
        '| --- | --- |',
        '| PHP | 8.4+ |',
        '| Laravel | 12.41.1+ or 13.x |',
    ]);

    file_put_contents($root . '/docs/getting-started/install.md', $installDocs);
    file_put_contents($root . '/docs/getting-started/quickstart.md', $installDocs);
    file_put_contents($root . '/.docker/Dockerfile', "RUN apt-get install php{$dockerPhpVersion}\nCOPY php/php.ini /etc/php/{$dockerPhpVersion}/cli/conf.d/99-capell.ini\n");
    file_put_contents($root . '/.github/workflows/test-fast-pr.yml', "matrix:\n  include:\n    - php: 8.4\n      laravel: 12.*\n    - php: 8.4\n      laravel: 13.*\n");
    file_put_contents($root . '/.github/workflows/test-full.yml', "matrix:\n  include:\n    - php: 8.4\n      laravel: 12.*\n    - php: 8.4\n      laravel: 13.*\n");
}

/**
 * @return array{int, string}
 */
function supportContractRun(string $root): array
{
    $command = implode(' ', [
        'CAPELL_SUPPORT_CONTRACT_ROOT=' . escapeshellarg($root),
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 2) . '/scripts/check-support-contract.php'),
        '2>&1',
    ]);

    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

function supportContractDeleteDirectory(string $path): void
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
