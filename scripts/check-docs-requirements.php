<?php

declare(strict_types=1);

$configuredRepositoryRoot = getenv('CAPELL_DOCS_REQUIREMENTS_ROOT') ?: dirname(__DIR__);
$repositoryRoot = realpath($configuredRepositoryRoot);

if ($repositoryRoot === false) {
    fwrite(STDERR, sprintf('Missing repository root: %s%s', $configuredRepositoryRoot, PHP_EOL));

    exit(1);
}

/**
 * Documented requirements tables that must agree with the composer manifests.
 *
 * Each table names the manifest its constraints come from and the rows to
 * verify. Every version number in a constraint must appear in the documented
 * row, every version number in the documented row must derive from the
 * constraint (so retired lines cannot linger after a bump), and rows with
 * requireRawConstraint must contain the constraint string verbatim. A row may
 * override the table manifest when its claim comes from another package —
 * e.g. the Core and Installer readmes describe the Filament and Laravel lines
 * they support through other packages' constraints.
 */
$rootRows = [
    'PHP' => ['package' => 'php', 'requireRawConstraint' => false],
    'Laravel' => ['package' => 'laravel/framework', 'requireRawConstraint' => false],
    'Filament' => ['package' => 'filament/filament', 'requireRawConstraint' => true],
];

$documentedTables = [
    ['path' => 'README.md', 'manifest' => 'composer.json', 'rows' => $rootRows],
    ['path' => 'docs/getting-started/quickstart.md', 'manifest' => 'composer.json', 'rows' => $rootRows],
    ['path' => 'docs/getting-started/install.md', 'manifest' => 'composer.json', 'rows' => $rootRows],
    ['path' => 'packages/core/README.md', 'manifest' => 'packages/core/composer.json', 'rows' => [
        'PHP' => ['package' => 'php', 'requireRawConstraint' => true],
        'Laravel' => ['package' => 'illuminate/support', 'requireRawConstraint' => true],
        'Filament support' => ['package' => 'filament/filament', 'requireRawConstraint' => true, 'manifest' => 'composer.json'],
        'Symfony filesystem/process' => ['package' => 'symfony/process', 'requireRawConstraint' => false],
        'Symfony HTML sanitizer' => ['package' => 'symfony/html-sanitizer', 'requireRawConstraint' => false],
    ]],
    ['path' => 'packages/admin/README.md', 'manifest' => 'packages/admin/composer.json', 'rows' => [
        'PHP' => ['package' => 'php', 'requireRawConstraint' => true],
        'Laravel' => ['package' => 'laravel/framework', 'requireRawConstraint' => true],
        'Filament' => ['package' => 'filament/filament', 'requireRawConstraint' => true],
    ]],
    ['path' => 'packages/frontend/README.md', 'manifest' => 'packages/frontend/composer.json', 'rows' => [
        'PHP' => ['package' => 'php', 'requireRawConstraint' => true],
        'Laravel' => ['package' => 'laravel/framework', 'requireRawConstraint' => true],
        'Livewire' => ['package' => 'livewire/livewire', 'requireRawConstraint' => false],
    ]],
    ['path' => 'packages/installer/README.md', 'manifest' => 'packages/installer/composer.json', 'rows' => [
        'PHP' => ['package' => 'php', 'requireRawConstraint' => true],
        'Laravel' => ['package' => 'illuminate/support', 'requireRawConstraint' => true, 'manifest' => 'packages/core/composer.json'],
    ]],
    ['path' => 'packages/marketplace/README.md', 'manifest' => 'packages/marketplace/composer.json', 'rows' => [
        'PHP' => ['package' => 'php', 'requireRawConstraint' => true],
        'Laravel' => ['package' => 'laravel/framework', 'requireRawConstraint' => true],
    ]],
];

$manifestRequirementsByPath = [];
$failures = [];

foreach ($documentedTables as $documentedTable) {
    $relativeDocumentPath = $documentedTable['path'];
    $documentPath = $repositoryRoot . '/' . $relativeDocumentPath;
    $documentContents = file_get_contents($documentPath);

    if ($documentContents === false) {
        fwrite(STDERR, "Unable to read {$documentPath}.\n");

        exit(1);
    }

    foreach ($documentedTable['rows'] as $rowLabel => $expectation) {
        $relativeManifestPath = $expectation['manifest'] ?? $documentedTable['manifest'];
        $manifestRequirements = $manifestRequirementsByPath[$relativeManifestPath]
            ??= readManifestRequirements($repositoryRoot . '/' . $relativeManifestPath);
        $constraint = $manifestRequirements[$expectation['package']] ?? null;

        if ($constraint === null) {
            $failures[] = sprintf('%s no longer requires %s — update $documentedTables in scripts/check-docs-requirements.php.', $relativeManifestPath, $expectation['package']);

            continue;
        }

        if (preg_match('/^\|\s*' . preg_quote($rowLabel, '/') . '\s*\|(.+)\|\s*$/m', $documentContents, $rowMatch) !== 1) {
            $failures[] = sprintf("%s: no requirements table row labelled '%s'.", $relativeDocumentPath, $rowLabel);

            continue;
        }

        $documentedRow = $rowMatch[1];
        $constraintVersions = extractVersionNumbers($constraint);

        foreach ($constraintVersions as $expectedVersion) {
            if (! str_contains($documentedRow, $expectedVersion)) {
                $failures[] = sprintf("%s: '%s' row does not mention %s (%s requires %s: %s).", $relativeDocumentPath, $rowLabel, $expectedVersion, $relativeManifestPath, $expectation['package'], $constraint);
            }
        }

        foreach (extractVersionNumbers($documentedRow) as $documentedVersion) {
            if (! versionDerivesFromConstraint($documentedVersion, $constraintVersions)) {
                $failures[] = sprintf("%s: '%s' row mentions %s, which does not derive from the current constraint (%s requires %s: %s) — remove the retired version line.", $relativeDocumentPath, $rowLabel, $documentedVersion, $relativeManifestPath, $expectation['package'], $constraint);
            }
        }

        if ($expectation['requireRawConstraint'] && ! str_contains($documentedRow, $constraint)) {
            $failures[] = sprintf("%s: '%s' row does not contain the raw constraint %s.", $relativeDocumentPath, $rowLabel, $constraint);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Documented requirements disagree with composer.json:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf('- %s%s', $failure, PHP_EOL));
    }

    exit(2);
}

$tableCount = count($documentedTables);

printf('%d requirements tables agree with composer.json.%s', $tableCount, PHP_EOL);

exit(0);

/**
 * @return array<string, string>
 */
function readManifestRequirements(string $manifestPath): array
{
    $manifestContents = file_get_contents($manifestPath);

    if ($manifestContents === false) {
        fwrite(STDERR, "Unable to read {$manifestPath}.\n");

        exit(1);
    }

    $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);

    return $manifest['require'] ?? [];
}

/**
 * Extract every distinct version number from a composer constraint or a
 * documented table row, trimming trailing ".0" minors so "^13.0" expects "13".
 *
 * @return list<string>
 */
function extractVersionNumbers(string $constraint): array
{
    if (preg_match_all('/\d+(?:\.\d+)*/', $constraint, $versionMatches) === false) {
        return [];
    }

    $versionNumbers = [];

    foreach ($versionMatches[0] as $rawVersion) {
        $trimmedVersion = preg_replace('/(?:\.0)+$/', '', $rawVersion) ?? $rawVersion;
        $versionNumbers[] = $trimmedVersion;
    }

    return array_values(array_unique($versionNumbers));
}

/**
 * A documented version derives from the constraint when it sits on the same
 * version line as one of the constraint's versions: the shorter of the two
 * dot-separated sequences must be a prefix of the longer. "13" matches a
 * documented "13.x" or "13.0", while a stale "12.41.1" shares no line with a
 * "^13.0" constraint and fails.
 *
 * @param  list<string>  $constraintVersions
 */
function versionDerivesFromConstraint(string $documentedVersion, array $constraintVersions): bool
{
    $documentedSegments = explode('.', $documentedVersion);

    foreach ($constraintVersions as $constraintVersion) {
        $constraintSegments = explode('.', $constraintVersion);
        $sharedSegmentCount = min(count($documentedSegments), count($constraintSegments));

        if (array_slice($documentedSegments, 0, $sharedSegmentCount) === array_slice($constraintSegments, 0, $sharedSegmentCount)) {
            return true;
        }
    }

    return false;
}
