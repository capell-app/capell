<?php

declare(strict_types=1);

$repositoryRoot = getenv('CAPELL_DOCS_LINKS_ROOT') ?: dirname(__DIR__);

$markdownFiles = collectMarkdownFiles($repositoryRoot);
$brokenLinks = [];
$relativeLinkCount = 0;

foreach ($markdownFiles as $markdownFile) {
    $contents = file_get_contents($markdownFile);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$markdownFile}.\n");

        exit(1);
    }

    $sourceDirectory = dirname($markdownFile);
    $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

    foreach ($lines as $lineIndex => $lineContents) {
        if (preg_match_all('/\]\(([^)]+)\)/', $lineContents, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $rawTarget) {
            $target = trim($rawTarget);

            if (! isRelativeLink($target)) {
                continue;
            }

            $resolvableTarget = stripAnchor($target);

            if ($resolvableTarget === '') {
                continue;
            }

            $relativeLinkCount++;

            $resolvedPath = $sourceDirectory . '/' . $resolvableTarget;

            if (file_exists($resolvedPath)) {
                continue;
            }

            $relativeSource = relativeToRoot($markdownFile, $repositoryRoot);
            $lineNumber = $lineIndex + 1;
            $brokenLinks[] = "{$relativeSource}:{$lineNumber} -> {$target}";
        }
    }
}

$fileCount = count($markdownFiles);
$brokenCount = count($brokenLinks);

printf(
    "%d files, %d relative links, %d broken\n",
    $fileCount,
    $relativeLinkCount,
    $brokenCount,
);

if ($brokenCount === 0) {
    fwrite(STDOUT, "All relative documentation links resolve.\n");

    exit(0);
}

$displayLimit = 50;
$displayedLinks = array_slice($brokenLinks, 0, $displayLimit);

fwrite(STDERR, "Broken relative documentation links found:\n");

foreach ($displayedLinks as $brokenLink) {
    fwrite(STDERR, "- {$brokenLink}\n");
}

$remaining = $brokenCount - count($displayedLinks);

if ($remaining > 0) {
    fwrite(STDERR, "...and {$remaining} more.\n");
}

exit(1);

/**
 * Collect every markdown file the checker should scan, relative to the repository root.
 *
 * @return list<string>
 */
function collectMarkdownFiles(string $repositoryRoot): array
{
    $markdownFiles = [];

    $rootReadme = $repositoryRoot . '/README.md';

    if (is_file($rootReadme)) {
        $markdownFiles[] = $rootReadme;
    }

    foreach (recursiveMarkdownFiles($repositoryRoot . '/docs') as $docsFile) {
        $markdownFiles[] = $docsFile;
    }

    $packageDirectories = glob($repositoryRoot . '/packages/*', GLOB_ONLYDIR) ?: [];

    foreach ($packageDirectories as $packageDirectory) {
        $packageReadme = $packageDirectory . '/README.md';

        if (is_file($packageReadme)) {
            $markdownFiles[] = $packageReadme;
        }

        foreach (recursiveMarkdownFiles($packageDirectory . '/docs') as $packageDocsFile) {
            $markdownFiles[] = $packageDocsFile;
        }
    }

    $markdownFiles = array_values(array_unique($markdownFiles));
    sort($markdownFiles);

    return $markdownFiles;
}

/**
 * Recursively collect every *.md file beneath the given directory.
 *
 * @return list<string>
 */
function recursiveMarkdownFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    $markdownFiles = [];

    foreach ($iterator as $entry) {
        if (! $entry instanceof SplFileInfo) {
            continue;
        }

        if ($entry->isFile() && strtolower($entry->getExtension()) === 'md') {
            $markdownFiles[] = $entry->getPathname();
        }
    }

    return $markdownFiles;
}

function isRelativeLink(string $target): bool
{
    if ($target === '') {
        return false;
    }

    foreach (['http:', 'https:', 'mailto:', '#'] as $skippedPrefix) {
        if (str_starts_with($target, $skippedPrefix)) {
            return false;
        }
    }

    return true;
}

function stripAnchor(string $target): string
{
    $anchorPosition = strpos($target, '#');

    if ($anchorPosition === false) {
        return $target;
    }

    return substr($target, 0, $anchorPosition);
}

function relativeToRoot(string $path, string $repositoryRoot): string
{
    $prefix = $repositoryRoot . '/';

    if (str_starts_with($path, $prefix)) {
        return substr($path, strlen($prefix));
    }

    return $path;
}
