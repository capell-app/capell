<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$configuredRepositoryRoot = getenv('CAPELL_DOCS_COMMANDS_ROOT') ?: dirname(__DIR__);
$repositoryRoot = realpath($configuredRepositoryRoot);

if ($repositoryRoot === false) {
    fwrite(STDERR, sprintf('Missing repository root: %s%s', $configuredRepositoryRoot, PHP_EOL));

    exit(1);
}

$configuredPaths = getenv('CAPELL_DOCS_COMMANDS_PATHS');
$documentPaths = is_string($configuredPaths) && trim($configuredPaths) !== ''
    ? array_values(array_filter(array_map(trim(...), explode(',', $configuredPaths))))
    : discoverDocumentationPaths($repositoryRoot);

$registeredCommands = registeredCapellCommandDefinitions($repositoryRoot);
$failures = [];
$exampleCount = 0;
$optionalPackageExampleCount = 0;

foreach ($documentPaths as $relativeDocumentPath) {
    $documentPath = $repositoryRoot . '/' . $relativeDocumentPath;
    $documentContents = file_get_contents($documentPath);

    if ($documentContents === false) {
        fwrite(STDERR, sprintf('Unable to read %s.%s', $documentPath, PHP_EOL));

        exit(1);
    }

    foreach (documentedCapellCommandExamples($documentContents) as $example) {
        $location = sprintf('%s:%d', $relativeDocumentPath, $example['line']);

        if ($example['optional_package']) {
            if (array_key_exists($example['command'], $registeredCommands)) {
                $failures[] = sprintf(
                    '%s: %s is registered by Foundation; remove the optional-package marker.',
                    $location,
                    $example['command'],
                );

                continue;
            }

            $optionalPackageExampleCount++;

            continue;
        }

        $exampleCount++;
        $registeredOptions = $registeredCommands[$example['command']] ?? null;

        if ($registeredOptions === null) {
            $failures[] = sprintf('%s: unknown documented command [%s].', $location, $example['command']);

            continue;
        }

        foreach ($example['options'] as $option) {
            if (! in_array($option, $registeredOptions, true)) {
                $failures[] = sprintf(
                    '%s: unknown option [--%s] for [%s].',
                    $location,
                    $option,
                    $example['command'],
                );
            }
        }

        if ($example['command'] !== 'capell:install') {
            continue;
        }

        $failures = [
            ...$failures,
            ...validateDocumentedInstallUserOptions($example, $location),
        ];
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Documented Capell Artisan commands disagree with the registered command definitions:\n");

    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, sprintf('- %s%s', $failure, PHP_EOL));
    }

    exit(2);
}

printf(
    '%d documented Capell command %s with the registered command definitions.%s',
    $exampleCount,
    $exampleCount === 1 ? 'example agrees' : 'examples agree',
    PHP_EOL,
);

if ($optionalPackageExampleCount > 0) {
    printf(
        '%d optional-package %s %s not checked because %s %s only after package installation.%s',
        $optionalPackageExampleCount,
        $optionalPackageExampleCount === 1 ? 'example' : 'examples',
        $optionalPackageExampleCount === 1 ? 'was' : 'were',
        $optionalPackageExampleCount === 1 ? 'its command' : 'their commands',
        $optionalPackageExampleCount === 1 ? 'registers' : 'register',
        PHP_EOL,
    );
}

/**
 * Discover the repository-owned Markdown surface. Companion-package examples
 * must opt out at their fence because their commands are unavailable until the
 * package provider is installed.
 *
 * @return list<string>
 */
function discoverDocumentationPaths(string $repositoryRoot): array
{
    $paths = [];

    if (is_file($repositoryRoot . '/README.md')) {
        $paths[] = 'README.md';
    }

    $documentationRoot = $repositoryRoot . '/docs';

    if (! is_dir($documentationRoot)) {
        return $paths;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($documentationRoot, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
            continue;
        }

        $paths[] = substr($file->getPathname(), strlen($repositoryRoot) + 1);
    }

    sort($paths);

    return $paths;
}

/**
 * Load the command names and options from Symfony's JSON command list.
 *
 * Tests may supply a captured registry. The live gate always boots the
 * repository's Testbench application so provider registration remains the
 * source of truth.
 *
 * @return array<string, list<string>>
 */
function registeredCapellCommandDefinitions(string $repositoryRoot): array
{
    $configuredRegistryPath = getenv('CAPELL_DOCS_COMMANDS_REGISTRY');

    if (is_string($configuredRegistryPath) && $configuredRegistryPath !== '') {
        $registryContents = file_get_contents($configuredRegistryPath);

        if ($registryContents === false) {
            fwrite(STDERR, sprintf('Unable to read command registry: %s%s', $configuredRegistryPath, PHP_EOL));

            exit(1);
        }
    } else {
        $process = new Process(
            [PHP_BINARY, $repositoryRoot . '/scripts/run-testbench-command.php', 'list', '--format=json'],
            $repositoryRoot,
        );
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            fwrite(STDERR, "Unable to load the registered Artisan command definitions.\n");
            fwrite(STDERR, $process->getErrorOutput());

            exit(1);
        }

        $registryContents = $process->getOutput();
    }

    try {
        $registry = json_decode($registryContents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $jsonException) {
        fwrite(STDERR, sprintf('Unable to decode the Artisan command registry: %s%s', $jsonException->getMessage(), PHP_EOL));

        exit(1);
    }

    if (! is_array($registry) || ! is_array($registry['commands'] ?? null)) {
        fwrite(STDERR, "The Artisan command registry has no commands list.\n");

        exit(1);
    }

    $definitions = [];

    foreach ($registry['commands'] as $command) {
        if (! is_array($command)) {
            continue;
        }

        $name = $command['name'] ?? null;

        if (! is_string($name) || ! str_starts_with($name, 'capell:')) {
            continue;
        }

        $options = $command['definition']['options'] ?? [];
        $registeredOptions = is_array($options)
            ? array_values(array_filter(array_keys($options), is_string(...)))
            : [];
        $definitions[$name] = $registeredOptions;

        $usages = $command['usage'] ?? [];

        if (! is_array($usages)) {
            continue;
        }

        foreach ($usages as $usage) {
            if (is_string($usage) && preg_match('/^capell:[a-z0-9:_-]+$/i', $usage) === 1) {
                $definitions[$usage] = $registeredOptions;
            }
        }
    }

    ksort($definitions);

    return $definitions;
}

/**
 * @return list<array{command: string, options: list<string>, line: int, optional_package: bool}>
 */
function documentedCapellCommandExamples(string $documentContents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $documentContents) ?: [];
    $insideFence = false;
    $fenceCharacter = null;
    $buffer = null;
    $bufferLine = null;
    $optionalPackageFence = false;
    $optionalPackageNextFence = false;
    $examples = [];

    foreach ($lines as $lineIndex => $line) {
        if (! $insideFence && trim($line) === '<!-- capell-docs-commands: optional-package -->') {
            $optionalPackageNextFence = true;

            continue;
        }

        if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $fenceMatch) === 1) {
            $delimiterCharacter = $fenceMatch[1][0];

            if (! $insideFence) {
                $insideFence = true;
                $fenceCharacter = $delimiterCharacter;
                $optionalPackageFence = $optionalPackageNextFence;
                $optionalPackageNextFence = false;
            } elseif ($delimiterCharacter === $fenceCharacter) {
                $insideFence = false;
                $fenceCharacter = null;
                $buffer = null;
                $bufferLine = null;
                $optionalPackageFence = false;
            }

            continue;
        }

        if (! $insideFence) {
            continue;
        }

        if ($buffer !== null) {
            $buffer .= ' ' . trim($line);

            if (lineContinuesShellCommand($line)) {
                $buffer = removeShellContinuation($buffer);

                continue;
            }

            $example = parseDocumentedCapellCommand($buffer, $bufferLine ?? $lineIndex + 1);

            if ($example !== null) {
                $examples[] = [...$example, 'optional_package' => $optionalPackageFence];
            }

            $buffer = null;
            $bufferLine = null;

            continue;
        }

        if (! str_contains($line, 'php artisan capell:')) {
            continue;
        }

        $buffer = trim($line);
        $bufferLine = $lineIndex + 1;

        if (lineContinuesShellCommand($line)) {
            $buffer = removeShellContinuation($buffer);

            continue;
        }

        $example = parseDocumentedCapellCommand($buffer, $bufferLine);

        if ($example !== null) {
            $examples[] = [...$example, 'optional_package' => $optionalPackageFence];
        }

        $buffer = null;
        $bufferLine = null;
    }

    return $examples;
}

function lineContinuesShellCommand(string $line): bool
{
    return preg_match('/\\\\\s*$/', $line) === 1;
}

function removeShellContinuation(string $line): string
{
    return preg_replace('/\\\\\s*$/', '', $line) ?? $line;
}

/**
 * @return array{command: string, options: list<string>, line: int}|null
 */
function parseDocumentedCapellCommand(string $commandLine, int $line): ?array
{
    if (preg_match(
        '/\bphp\s+artisan\s+(capell:[a-z0-9:_-]+)/i',
        $commandLine,
        $commandMatch,
        PREG_OFFSET_CAPTURE,
    ) !== 1) {
        return null;
    }

    $commandName = $commandMatch[1][0];
    $commandEndOffset = $commandMatch[1][1] + strlen($commandName);
    $commandOptions = substr($commandLine, $commandEndOffset);
    $options = [];

    if (preg_match_all('/(?:^|\s)--([a-z][a-z0-9-]*)(?==|\s|$)/i', $commandOptions, $optionMatches) !== false) {
        $options = array_values(array_unique($optionMatches[1]));
    }

    return [
        'command' => $commandName,
        'options' => $options,
        'line' => $line,
    ];
}

/**
 * @param  array{command: string, options: list<string>, line: int}  $example
 * @return list<string>
 */
function validateDocumentedInstallUserOptions(array $example, string $location): array
{
    $options = $example['options'];
    $newUserOptions = ['name', 'email', 'password'];
    $presentNewUserOptions = array_values(array_intersect($newUserOptions, $options));
    $missingNewUserOptions = array_values(array_diff($newUserOptions, $options));
    $failures = [];

    if (in_array('user', $options, true) && $presentNewUserOptions !== []) {
        $failures[] = sprintf(
            '%s: --user selects an existing default author and cannot be combined with --name, --email, or --password.',
            $location,
        );

        return $failures;
    }

    if ($presentNewUserOptions !== [] && $missingNewUserOptions !== []) {
        $failures[] = sprintf(
            '%s: first-user install must pass --name, --email, and --password together; missing %s.',
            $location,
            implode(', ', array_map(static fn (string $option): string => '--' . $option, $missingNewUserOptions)),
        );

        return $failures;
    }

    $nonInteractive = in_array('no-interaction', $options, true)
        || in_array('production', $options, true);

    if (! $nonInteractive || count($presentNewUserOptions) !== count($newUserOptions)) {
        return $failures;
    }

    $missingPromptOptions = [];

    if (! in_array('url', $options, true)) {
        $missingPromptOptions[] = '--url';
    }

    if (array_intersect(['package-mode', 'packages', 'all-packages'], $options) === []) {
        $missingPromptOptions[] = '--package-mode or --packages or --all-packages';
    }

    foreach (['theme', 'clear-cache', 'install-welcome-route'] as $requiredOption) {
        if (! in_array($requiredOption, $options, true)) {
            $missingPromptOptions[] = '--' . $requiredOption;
        }
    }

    if ($missingPromptOptions !== []) {
        $failures[] = sprintf(
            '%s: non-interactive first-user install is missing explicit prompt options: %s.',
            $location,
            implode(', ', $missingPromptOptions),
        );
    }

    return $failures;
}
