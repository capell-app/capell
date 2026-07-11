<?php

declare(strict_types=1);

$configuredRepositoryRoot = getenv('CAPELL_DOCS_REQUIREMENTS_ROOT') ?: dirname(__DIR__);
$repositoryRoot = realpath($configuredRepositoryRoot);

if ($repositoryRoot === false) {
    fwrite(STDERR, "Missing repository root: {$configuredRepositoryRoot}\n");

    exit(1);
}

$composerManifestPath = $repositoryRoot . '/composer.json';
$composerManifestContents = file_get_contents($composerManifestPath);

if ($composerManifestContents === false) {
    fwrite(STDERR, "Unable to read {$composerManifestPath}.\n");

    exit(1);
}

$composerManifest = json_decode($composerManifestContents, true, 512, JSON_THROW_ON_ERROR);
$composerRequirements = $composerManifest['require'] ?? [];

/**
 * Documented requirements tables that must agree with composer.json.
 */
$documentedTables = [
    'README.md',
    'docs/getting-started/quickstart.md',
    'docs/getting-started/install.md',
];

/**
 * For each table row label, the composer package whose constraint feeds the
 * expected tokens. Every version number in the constraint must appear in the
 * documented row; the Filament row must also contain the raw constraint.
 */
$rowExpectations = [
    'PHP' => ['package' => 'php', 'requireRawConstraint' => false],
    'Laravel' => ['package' => 'laravel/framework', 'requireRawConstraint' => false],
    'Filament' => ['package' => 'filament/filament', 'requireRawConstraint' => true],
];

$failures = [];

foreach ($documentedTables as $relativeDocumentPath) {
    $documentPath = $repositoryRoot . '/' . $relativeDocumentPath;
    $documentContents = file_get_contents($documentPath);

    if ($documentContents === false) {
        fwrite(STDERR, "Unable to read {$documentPath}.\n");

        exit(1);
    }

    foreach ($rowExpectations as $rowLabel => $expectation) {
        $constraint = $composerRequirements[$expectation['package']] ?? null;

        if ($constraint === null) {
            $failures[] = "composer.json no longer requires {$expectation['package']} — update \$rowExpectations in scripts/check-docs-requirements.php.";

            continue;
        }

        if (preg_match('/^\|\s*' . preg_quote($rowLabel, '/') . '\s*\|(.+)\|\s*$/m', $documentContents, $rowMatch) !== 1) {
            $failures[] = "{$relativeDocumentPath}: no requirements table row labelled '{$rowLabel}'.";

            continue;
        }

        $documentedRow = $rowMatch[1];

        foreach (extractVersionNumbers($constraint) as $expectedVersion) {
            if (! str_contains($documentedRow, $expectedVersion)) {
                $failures[] = "{$relativeDocumentPath}: '{$rowLabel}' row does not mention {$expectedVersion} (composer.json requires {$expectation['package']}: {$constraint}).";
            }
        }

        if ($expectation['requireRawConstraint'] && ! str_contains($documentedRow, $constraint)) {
            $failures[] = "{$relativeDocumentPath}: '{$rowLabel}' row does not contain the raw constraint {$constraint}.";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Documented requirements disagree with composer.json:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    exit(2);
}

$tableCount = count($documentedTables);

echo "{$tableCount} requirements tables agree with composer.json.\n";

exit(0);

/**
 * Extract every distinct version number from a composer constraint,
 * trimming trailing ".0" minors so "^13.0" expects "13".
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
