<?php

declare(strict_types=1);

$configuredRepositoryRoot = getenv('CAPELL_DOCS_ENV_ROOT') ?: dirname(__DIR__);
$repositoryRoot = realpath($configuredRepositoryRoot);

if ($repositoryRoot === false) {
    fwrite(STDERR, "Missing repository root: {$configuredRepositoryRoot}\n");

    exit(1);
}

/**
 * Variables documented in env blocks that this monorepo intentionally does
 * not read: Laravel/infrastructure vars, and vars consumed by external
 * first-party packages. Every entry needs a reason.
 */
$allowedExternalVariables = [
    'APP_ENV' => 'Laravel framework',
    'APP_KEY' => 'Laravel framework',
    'APP_URL' => 'Laravel framework',
    'APP_DEBUG' => 'Laravel framework',
    'DB_CONNECTION' => 'Laravel framework',
    'DB_DATABASE' => 'Laravel framework',
    'DB_HOST' => 'Laravel framework',
    'DB_PASSWORD' => 'Laravel framework',
    'DB_PORT' => 'Laravel framework',
    'DB_USERNAME' => 'Laravel framework',
    'CACHE_STORE' => 'Laravel framework',
    'QUEUE_CONNECTION' => 'Laravel framework',
    'SESSION_DRIVER' => 'Laravel framework',
    'DEBUG_SKIP_CACHE' => 'read by the external capell-app/html-cache package',
];

$envReaderPattern = '/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/';
$readVariables = [];

foreach (collectPhpFiles($repositoryRoot . '/packages') as $phpFile) {
    $phpContents = file_get_contents($phpFile);

    if ($phpContents === false) {
        fwrite(STDERR, "Unable to read {$phpFile}.\n");

        exit(1);
    }

    if (preg_match_all($envReaderPattern, $phpContents, $readerMatches) > 0) {
        foreach ($readerMatches[1] as $readVariableName) {
            $readVariables[$readVariableName] = true;
        }
    }
}

$failures = [];
$documentedCount = 0;
$markdownFiles = collectMarkdownFiles($repositoryRoot . '/docs');
$markdownFiles[] = $repositoryRoot . '/README.md';

foreach ($markdownFiles as $markdownFile) {
    $markdownContents = file_get_contents($markdownFile);

    if ($markdownContents === false) {
        fwrite(STDERR, "Unable to read {$markdownFile}.\n");

        exit(1);
    }

    if (preg_match_all('/```env\n(.*?)```/s', $markdownContents, $blockMatches) === 0) {
        continue;
    }

    $relativePath = substr($markdownFile, strlen($repositoryRoot) + 1);

    foreach ($blockMatches[1] as $envBlockContents) {
        if (preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $envBlockContents, $variableMatches) === 0) {
            continue;
        }

        foreach ($variableMatches[1] as $documentedVariable) {
            $documentedCount++;

            if (isset($readVariables[$documentedVariable]) || isset($allowedExternalVariables[$documentedVariable])) {
                continue;
            }

            $failures[] = "{$relativePath}: documents {$documentedVariable} but no env('{$documentedVariable}') call exists under packages/ and it is not allowlisted.";
        }
    }
}

if ($failures !== []) {
    sort($failures);

    fwrite(STDERR, "Documented env var(s) that nothing reads:\n");

    foreach (array_unique($failures) as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    fwrite(STDERR, "\nFix the docs to use the real variable name, or add a justified entry to \$allowedExternalVariables in scripts/check-docs-env-vars.php.\n");

    exit(2);
}

echo "{$documentedCount} documented env assignments verified against packages/ env() readers.\n";

exit(0);

/**
 * @return list<string>
 */
function collectPhpFiles(string $directory): array
{
    return collectFilesByExtension($directory, 'php');
}

/**
 * @return list<string>
 */
function collectMarkdownFiles(string $directory): array
{
    return collectFilesByExtension($directory, 'md');
}

/**
 * @return list<string>
 */
function collectFilesByExtension(string $directory, string $extension): array
{
    $collectedFiles = [];
    $directoryIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($directoryIterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === $extension) {
            $collectedFiles[] = $fileInfo->getRealPath();
        }
    }

    sort($collectedFiles);

    return $collectedFiles;
}
