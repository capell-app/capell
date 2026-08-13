<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('verifies the live root package and documentation contract', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-root-docs.php'], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('Root documentation contract is verified.');
});

it('accepts a supported aligned aggregate fixture', function (): void {
    $root = rootDocsFixture();

    try {
        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Root documentation contract is verified.');
    } finally {
        deleteRootDocsFixture($root);
    }
});

it('allows repository instructions at the root', function (): void {
    $root = rootDocsFixture();

    try {
        file_put_contents($root . '/AGENTS.md', '# Repository instructions');

        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Root documentation contract is verified.');
    } finally {
        deleteRootDocsFixture($root);
    }
});

it('allows Claude to share the canonical repository instructions', function (): void {
    $root = rootDocsFixture();

    try {
        file_put_contents($root . '/AGENTS.md', '# Repository instructions');
        symlink('AGENTS.md', $root . '/CLAUDE.md');

        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Root documentation contract is verified.');
    } finally {
        deleteRootDocsFixture($root);
    }
});

it('reports package truth drift and unexpected root handoff files', function (): void {
    $root = rootDocsFixture();

    try {
        file_put_contents($root . '/README.md', '# Capell');
        file_put_contents($root . '/HANDOFF.md', 'scratch');

        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('Root documentation contract failed:')
            ->and($output)->toContain('README.md is missing package truth')
            ->and($output)->toContain('HANDOFF.md');
    } finally {
        deleteRootDocsFixture($root);
    }
});

it('rejects retired private and schema-driven positioning', function (string $retiredClaim): void {
    $root = rootDocsFixture();

    try {
        file_put_contents($root . '/README.md', file_get_contents($root . '/README.md') . (PHP_EOL . $retiredClaim));

        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('README.md contains retired package positioning');
    } finally {
        deleteRootDocsFixture($root);
    }
})->with([
    'private foundation distribution' => 'Install the private foundation distribution.',
    'private package' => 'This is a private package.',
    'schema-driven category' => 'Capell is a schema-driven CMS.',
]);

function rootDocsFixture(): string
{
    $root = sys_get_temp_dir() . '/capell-root-docs-' . bin2hex(random_bytes(8));
    mkdir($root, 0777, true);

    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'capell-app/capell',
        'description' => 'The supported, version-aligned Capell foundation aggregate for Core, Admin, Frontend, Installer, and Marketplace.',
        'replace' => [
            'capell-app/admin' => 'self.version',
            'capell-app/core' => 'self.version',
            'capell-app/frontend' => 'self.version',
            'capell-app/installer' => 'self.version',
            'capell-app/marketplace' => 'self.version',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    file_put_contents($root . '/README.md', implode("\n", [
        '**The first editable page is easy. Capell solves what comes next.**',
        'Capell is an open-source CMS for Laravel. Build reusable page blueprints, preview through the real theme, use revision comparison and run repeatable upgrades.',
        'Capell Foundation is MIT-licensed. Your pages render inside your Laravel application.',
        'Capell is not a hosted CMS and does not ship a public content-delivery API.',
        'Install with `capell-app/installer`; the aligned aggregate is `capell-app/capell`.',
    ]));

    return $root;
}

/**
 * @return array{int, string}
 */
function runRootDocsCheck(string $root): array
{
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-root-docs.php'],
        $root,
        ['CAPELL_ROOT_DOCS_ROOT' => $root],
    );
    $process->run();

    return [$process->getExitCode() ?? 1, $process->getOutput() . $process->getErrorOutput()];
}

function deleteRootDocsFixture(string $path): void
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
