<?php

declare(strict_types=1);

$configuredRepositoryRoot = getenv('CAPELL_DOCS_ORPHANS_ROOT') ?: dirname(__DIR__);
$repositoryRoot = realpath($configuredRepositoryRoot);

if ($repositoryRoot === false) {
    fwrite(STDERR, sprintf('Missing repository root: %s%s', $configuredRepositoryRoot, PHP_EOL));

    exit(1);
}

/**
 * Files that are intentionally unreachable from the docs entry points.
 * Exact paths or trailing-slash prefixes, relative to the repository root.
 */
$allowedOrphans = [
    'docs/packages.md', // redirect stub kept alive for published docs-site URLs
    'docs/superpowers/', // internal plans and design records are not public documentation
];

$entryPoints = [
    $repositoryRoot . '/README.md',
    $repositoryRoot . '/docs/README.md',
];

$reachableFiles = [];
$queue = [];

foreach ($entryPoints as $entryPoint) {
    $resolvedEntryPoint = realpath($entryPoint);

    if ($resolvedEntryPoint === false) {
        fwrite(STDERR, sprintf('Missing docs entry point: %s%s', $entryPoint, PHP_EOL));

        exit(1);
    }

    $queue[] = $resolvedEntryPoint;
    $reachableFiles[$resolvedEntryPoint] = true;
}

while ($queue !== []) {
    $currentFile = array_shift($queue);
    $contents = file_get_contents($currentFile);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$currentFile}.\n");

        exit(1);
    }

    if (preg_match_all('/\]\(([^)\s#]+)(?:#[^)]*)?\)/', $contents, $linkMatches) === false) {
        continue;
    }

    foreach ($linkMatches[1] as $linkTarget) {
        $linkTarget = trim($linkTarget);

        if ($linkTarget === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $linkTarget) === 1) {
            continue; // absolute URL or mailto
        }

        if (! str_ends_with(strtolower($linkTarget), '.md')) {
            continue;
        }

        $resolvedTarget = realpath(dirname($currentFile) . '/' . $linkTarget);
        if ($resolvedTarget === false) {
            continue;
        }

        if (isset($reachableFiles[$resolvedTarget])) {
            continue;
        }

        $reachableFiles[$resolvedTarget] = true;
        $queue[] = $resolvedTarget;
    }
}

$orphanedFiles = [];
$documentationFiles = collectDocumentationFiles($repositoryRoot . '/docs');

foreach ($documentationFiles as $documentationFile) {
    $relativePath = substr($documentationFile, strlen($repositoryRoot) + 1);

    foreach ($allowedOrphans as $allowedOrphan) {
        if ($relativePath === $allowedOrphan) {
            continue 2;
        }

        if (str_starts_with($relativePath, $allowedOrphan)) {
            continue 2;
        }
    }

    if (! isset($reachableFiles[$documentationFile])) {
        $orphanedFiles[] = $relativePath;
    }
}

$checkedCount = count($documentationFiles);

if ($orphanedFiles !== []) {
    sort($orphanedFiles);

    fwrite(STDERR, "Orphaned documentation file(s) unreachable from README.md / docs/README.md:\n");

    foreach ($orphanedFiles as $orphanedFile) {
        fwrite(STDERR, sprintf('- %s%s', $orphanedFile, PHP_EOL));
    }

    fwrite(STDERR, "\nLink each page from its section index (docs/<section>/index.md or README.md), or add a justified entry to \$allowedOrphans in scripts/check-docs-orphans.php.\n");

    exit(2);
}

echo $checkedCount . ' docs files checked, all reachable from the docs entry points.
';

exit(0);

/**
 * @return list<string>
 */
function collectDocumentationFiles(string $directory): array
{
    $collectedFiles = [];
    $directoryIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($directoryIterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower((string) $fileInfo->getExtension()) === 'md') {
            $collectedFiles[] = $fileInfo->getRealPath();
        }
    }

    sort($collectedFiles);

    return $collectedFiles;
}
