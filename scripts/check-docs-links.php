<?php

declare(strict_types=1);

$repositoryRoot = getenv('CAPELL_DOCS_LINKS_ROOT') ?: dirname(__DIR__);

$markdownFiles = collectMarkdownFiles($repositoryRoot);
$brokenLinks = [];
$relativeLinkCount = 0;
$anchorsByMarkdownFile = [];

foreach ($markdownFiles as $markdownFile) {
    $contents = file_get_contents($markdownFile);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$markdownFile}.\n");

        exit(1);
    }

    $sourceDirectory = dirname($markdownFile);
    $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
    $insideFencedCodeBlock = false;

    foreach ($lines as $lineIndex => $lineContents) {
        if (isFencedCodeBlockDelimiter($lineContents)) {
            $insideFencedCodeBlock = ! $insideFencedCodeBlock;

            continue;
        }

        if ($insideFencedCodeBlock) {
            continue;
        }

        if (preg_match_all('/\]\(([^)]+)\)/', $lineContents, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $rawTarget) {
            $target = trim($rawTarget);

            if (! isRelativeLink($target)) {
                continue;
            }

            $relativeLinkCount++;
            $resolvableTarget = stripAnchor($target);
            $resolvedPath = $resolvableTarget === ''
                ? $markdownFile
                : $sourceDirectory . '/' . $resolvableTarget;

            if (! file_exists($resolvedPath)) {
                $relativeSource = relativeToRoot($markdownFile, $repositoryRoot);
                $lineNumber = $lineIndex + 1;
                $brokenLinks[] = "{$relativeSource}:{$lineNumber} -> {$target}";

                continue;
            }

            $anchor = extractAnchor($target);

            if ($anchor === null || anchorExists($resolvedPath, $anchorsByMarkdownFile, $anchor)) {
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

    foreach (['http:', 'https:', 'mailto:'] as $skippedPrefix) {
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

function extractAnchor(string $target): ?string
{
    $anchorPosition = strpos($target, '#');

    if ($anchorPosition === false) {
        return null;
    }

    return rawurldecode(substr($target, $anchorPosition + 1));
}

/**
 * @param  array<string, array<string, true>>  $anchorsByMarkdownFile
 */
function anchorExists(string $path, array &$anchorsByMarkdownFile, string $anchor): bool
{
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md') {
        return false;
    }

    if (! array_key_exists($path, $anchorsByMarkdownFile)) {
        $anchorsByMarkdownFile[$path] = anchorsForMarkdownFile($path);
    }

    return array_key_exists($anchor, $anchorsByMarkdownFile[$path]);
}

/**
 * @return array<string, true>
 */
function anchorsForMarkdownFile(string $markdownFile): array
{
    $contents = file_get_contents($markdownFile);

    if ($contents === false) {
        return [];
    }

    $anchors = [];
    $headingOccurrences = [];
    $insideFencedCodeBlock = false;
    $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

    foreach ($lines as $line) {
        if (isFencedCodeBlockDelimiter($line)) {
            $insideFencedCodeBlock = ! $insideFencedCodeBlock;

            continue;
        }

        if ($insideFencedCodeBlock) {
            continue;
        }

        if (preg_match('/^ {0,3}#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $headingMatches) === 1) {
            $slug = githubSlug($headingMatches[1]);

            if ($slug !== '') {
                $occurrence = $headingOccurrences[$slug] ?? 0;
                $headingOccurrences[$slug] = $occurrence + 1;
                $anchors[$occurrence === 0 ? $slug : "{$slug}-{$occurrence}"] = true;
            }
        }

        if (preg_match_all('/\bid\s*=\s*(["\'])([^"\']+)\1/i', $line, $idMatches) !== false) {
            foreach ($idMatches[2] as $id) {
                $anchors[$id] = true;
            }
        }
    }

    return $anchors;
}

function isFencedCodeBlockDelimiter(string $line): bool
{
    return preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1;
}

function githubSlug(string $heading): string
{
    $heading = strtolower($heading);
    $heading = str_replace(['`', '*', '_', '~', '[', ']', '(', ')', '!'], '', $heading);
    $heading = preg_replace('/[^\w\- ]/u', '', $heading) ?? '';

    return str_replace(' ', '-', $heading);
}

function relativeToRoot(string $path, string $repositoryRoot): string
{
    $prefix = $repositoryRoot . '/';

    if (str_starts_with($path, $prefix)) {
        return substr($path, strlen($prefix));
    }

    return $path;
}
