<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('verifies the live documented Capell command examples', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-docs-artisan-commands.php'], $root);

    $process->mustRun();

    expect($process->getOutput())
        ->toContain('documented Capell command examples agree with the registered command definitions.');
});

it('discovers fenced Capell commands across the documentation tree by default', function (): void {
    $root = documentationArtisanCommandsFixture('# Root');
    mkdir($root . '/docs/nested', 0777, true);
    file_put_contents($root . '/docs/nested/commands.md', <<<'MARKDOWN'
        ```bash
        php artisan capell:not-a-command
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root, discoverPaths: true);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('docs/nested/commands.md:2')
            ->and($output)->toContain('unknown documented command [capell:not-a-command]');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('wires the command checker into Composer, full preflight, and CI after dependencies exist', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $preflight = (string) file_get_contents($root . '/scripts/run-preflight.php');
    $workflow = (string) file_get_contents($root . '/.github/workflows/code-quality-and-styling.yml');
    $commandsDocumentation = (string) file_get_contents($root . '/docs/development/commands.md');
    $installPosition = strpos($workflow, 'name: Install Composer dependencies');
    $checkerPosition = strpos($workflow, 'name: Check documented Artisan commands');

    expect($composer['scripts']['check:docs-commands'])
        ->toBe('@php scripts/check-docs-artisan-commands.php')
        ->and($preflight)
        ->toContain("'docs-commands' => 'check:docs-commands'")
        ->and($commandsDocumentation)
        ->toContain('`composer check:docs-commands`')
        ->toContain('<!-- capell-docs-commands: optional-package -->')
        ->and($installPosition)->toBeInt()
        ->and($checkerPosition)->toBeInt()
        ->and($checkerPosition)->toBeGreaterThan($installPosition);
});

it('accepts registered commands and complete non-interactive first-user options', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        # Install

        ```bash
        php artisan capell:install \
          --no-interaction \
          --url=https://example.test \
          --package-mode=all \
          --theme=default \
          --name="Admin User" \
          --email=admin@example.test \
          --password=local-password \
          --clear-cache \
          --install-welcome-route
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('1 documented Capell command example agrees with the registered command definitions.');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('rejects an unregistered documented command', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        php artisan capell:not-a-command
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('unknown documented command [capell:not-a-command]');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('rejects an option that is absent from the registered command definition', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        php artisan capell:doctor --json --invented
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('unknown option [--invented] for [capell:doctor]');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('accepts aliases exposed by a registered command definition', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        php artisan capell:doctor:legacy --json
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('1 documented Capell command example agrees with the registered command definitions.');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('ignores wrapper options before the Artisan invocation', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        docker compose --env-file .env run --rm app php artisan capell:doctor --json
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('1 documented Capell command example agrees with the registered command definitions.');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('allows explicitly marked companion-package command fences', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        <!-- capell-docs-commands: optional-package -->
        ```bash
        composer require capell-app/example
        php artisan capell:example-install --package-option
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('0 documented Capell command examples agree')
            ->and($output)->toContain('1 optional-package example was not checked');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('rejects optional-package markers on registered Foundation commands', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        <!-- capell-docs-commands: optional-package -->
        ```bash
        php artisan capell:doctor --json
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('capell:doctor is registered by Foundation')
            ->and($output)->toContain('remove the optional-package marker');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('requires first-user options as a complete set', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        php artisan capell:install --no-interaction --name="Admin User" --email=admin@example.test
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('first-user install must pass --name, --email, and --password together')
            ->and($output)->toContain('missing --password');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('requires every prompt decision on a non-interactive first-user install', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        php artisan capell:install \
          --no-interaction \
          --url=https://example.test \
          --name="Admin User" \
          --email=admin@example.test \
          --password=local-password
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('non-interactive first-user install is missing explicit prompt options')
            ->and($output)->toContain('--package-mode or --packages or --all-packages')
            ->and($output)->toContain('--theme')
            ->and($output)->toContain('--clear-cache')
            ->and($output)->toContain('--install-welcome-route');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('keeps existing-user selection separate from account creation', function (): void {
    $root = documentationArtisanCommandsFixture(<<<'MARKDOWN'
        ```bash
        php artisan capell:install \
          --no-interaction \
          --user=existing@example.test \
          --name="Replacement User" \
          --email=replacement@example.test \
          --password=local-password
        ```
        MARKDOWN);

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('--user selects an existing default author')
            ->and($output)->toContain('cannot be combined with --name, --email, or --password');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

it('ignores inline commands because only copyable fenced examples are executable', function (): void {
    $root = documentationArtisanCommandsFixture(
        'Run `php artisan capell:not-a-command --invented` only after reading the package guide.',
    );

    try {
        [$exitCode, $output] = runDocumentationArtisanCommandsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('0 documented Capell command examples agree with the registered command definitions.');
    } finally {
        deleteDocumentationArtisanCommandsFixture($root);
    }
});

function documentationArtisanCommandsFixture(string $markdown): string
{
    $root = sys_get_temp_dir() . '/capell-docs-artisan-commands-' . bin2hex(random_bytes(8));
    mkdir($root, 0777, true);

    file_put_contents($root . '/README.md', $markdown . "\n");
    file_put_contents($root . '/command-registry.json', json_encode([
        'commands' => [
            documentationArtisanCommandDefinition('capell:install', [
                'demo',
                'fresh',
                'package-mode',
                'packages',
                'all-packages',
                'theme',
                'url',
                'user',
                'name',
                'email',
                'password',
                'clear-cache',
                'install-welcome-route',
                'production',
                'no-interaction',
            ]),
            documentationArtisanCommandDefinition(
                'capell:doctor',
                ['json', 'no-interaction'],
                ['capell:doctor:legacy'],
            ),
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    return $root;
}

/**
 * @param  list<string>  $options
 * @param  list<string>  $aliases
 * @return array{name: string, usage: list<string>, definition: array{options: array<string, array{name: string}>}}
 */
function documentationArtisanCommandDefinition(string $name, array $options, array $aliases = []): array
{
    $definitions = [];

    foreach ($options as $option) {
        $definitions[$option] = ['name' => '--' . $option];
    }

    return [
        'name' => $name,
        'usage' => [$name . ' [options]', ...$aliases],
        'definition' => ['options' => $definitions],
    ];
}

/**
 * @return array{int, string}
 */
function runDocumentationArtisanCommandsCheck(string $root, bool $discoverPaths = false): array
{
    $environment = [
        'CAPELL_DOCS_COMMANDS_ROOT' => $root,
        'CAPELL_DOCS_COMMANDS_REGISTRY' => $root . '/command-registry.json',
    ];

    if (! $discoverPaths) {
        $environment['CAPELL_DOCS_COMMANDS_PATHS'] = 'README.md';
    }

    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-docs-artisan-commands.php'],
        $root,
        $environment,
    );
    $process->run();

    return [$process->getExitCode() ?? 1, $process->getOutput() . $process->getErrorOutput()];
}

function deleteDocumentationArtisanCommandsFixture(string $path): void
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
