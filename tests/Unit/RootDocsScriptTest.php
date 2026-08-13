<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('verifies the live root package and documentation contract', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-root-docs.php'], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('Root documentation contract is verified.');
});

it('accepts an aligned aggregate with arbitrary README content', function (): void {
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

it('reports unexpected root handoff files', function (): void {
    $root = rootDocsFixture();

    try {
        file_put_contents($root . '/HANDOFF.md', 'scratch');

        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('Root documentation contract failed:')
            ->and($output)->toContain('HANDOFF.md');
    } finally {
        deleteRootDocsFixture($root);
    }
});

it('requires a root README file', function (): void {
    $root = rootDocsFixture();

    try {
        unlink($root . '/README.md');

        [$exitCode, $output] = runRootDocsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('README.md could not be read.');
    } finally {
        deleteRootDocsFixture($root);
    }
});

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
    file_put_contents($root . '/README.md', '# README copy can change without updating the guard.');

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
