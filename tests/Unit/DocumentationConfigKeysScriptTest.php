<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('verifies the live public config-key documentation contract', function (): void {
    $root = dirname(__DIR__, 2);
    $process = documentationConfigKeysProcess($root);

    $process->mustRun();

    expect($process->getOutput())->toContain('public config leaves covered');
});

it('accepts config paths documented directly or through their environment variable', function (): void {
    $root = documentationConfigKeysFixture(
        <<<'PHP'
<?php

return [
    'documented_path' => true,
    'documented_environment' => env('CAPELL_FIXTURE_LIMIT', 10),
];
PHP,
        '`capell-fixture.documented_path` and `CAPELL_FIXTURE_LIMIT` are documented.',
    );

    try {
        $process = documentationConfigKeysProcess($root);
        $process->mustRun();

        expect($process->getOutput())->toContain('2 public config leaves covered: 2 documented, 0 explicitly classified.');
    } finally {
        documentationConfigKeysDeleteDirectory($root);
    }
});

it('requires an exact reasoned classification for an undocumented config leaf', function (): void {
    $root = documentationConfigKeysFixture(
        <<<'PHP'
<?php

return [
    'internal' => [
        'cache_key' => 'fixture-cache',
    ],
];
PHP,
        '# Fixture docs',
    );

    try {
        $process = documentationConfigKeysProcess($root);
        $process->run();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('capell-fixture.internal.cache_key: undocumented public config leaf');

        file_put_contents(
            $root . '/scripts/docs-config-key-classifications.php',
            "<?php\n\nreturn ['capell-fixture.internal.cache_key' => 'Internal cache namespace.'];\n",
        );

        $process = documentationConfigKeysProcess($root);
        $process->mustRun();

        expect($process->getOutput())->toContain('1 explicitly classified.');
    } finally {
        documentationConfigKeysDeleteDirectory($root);
    }
});

it('rejects stale classifications', function (): void {
    $root = documentationConfigKeysFixture(
        "<?php\n\nreturn ['documented' => true];\n",
        '`capell-fixture.documented` is documented.',
        ['capell-fixture.removed' => 'No longer exists.'],
    );

    try {
        $process = documentationConfigKeysProcess($root);
        $process->run();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('classification does not match a current public config leaf');
    } finally {
        documentationConfigKeysDeleteDirectory($root);
    }
});

/**
 * @param  array<string, string>  $classifications
 */
function documentationConfigKeysFixture(
    string $config,
    string $documentation,
    array $classifications = [],
): string {
    $root = sys_get_temp_dir() . '/capell-doc-config-' . bin2hex(random_bytes(8));
    mkdir($root . '/packages/fixture/config', 0777, true);
    mkdir($root . '/scripts', 0777, true);
    mkdir($root . '/docs', 0777, true);

    file_put_contents($root . '/packages/fixture/config/capell-fixture.php', $config);
    file_put_contents($root . '/docs/configuration.md', $documentation);
    file_put_contents(
        $root . '/scripts/docs-config-key-classifications.php',
        "<?php\n\nreturn " . var_export($classifications, true) . ";\n",
    );

    return $root;
}

function documentationConfigKeysProcess(string $root): Process
{
    return new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-docs-config-keys.php'],
        $root,
        ['CAPELL_DOCS_CONFIG_ROOT' => $root],
    );
}

function documentationConfigKeysDeleteDirectory(string $path): void
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
