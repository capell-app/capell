<?php

declare(strict_types=1);

const REQUIRED_COVERAGE_PERCENTAGE = 90.0;

$arguments = array_slice($argv, 1);
$outputPath = null;
$inputPaths = [];

for ($index = 0; $index < count($arguments); $index++) {
    if ($arguments[$index] === '--output') {
        $outputPath = $arguments[++$index] ?? null;

        continue;
    }

    $inputPaths[] = $arguments[$index];
}

if ($outputPath === null || count($inputPaths) < 2) {
    throw new InvalidArgumentException(
        'Usage: php scripts/merge-clover-coverage.php --output <path> <clover> <clover> [...]',
    );
}

$documents = array_map(
    static function (string $path): DOMDocument {
        $document = new DOMDocument;

        if (! $document->load($path, LIBXML_NONET)) {
            throw new RuntimeException(sprintf('Unable to read Clover report [%s].', $path));
        }

        return $document;
    },
    $inputPaths,
);

$mergedDocument = array_shift($documents);
$mergedXPath = new DOMXPath($mergedDocument);

/** @var array<string, DOMElement> $mergedFiles */
$mergedFiles = [];

foreach ($mergedXPath->query('//file') ?: [] as $file) {
    if ($file instanceof DOMElement) {
        $mergedFiles[$file->getAttribute('name')] = $file;
    }
}

foreach ($documents as $document) {
    $xpath = new DOMXPath($document);

    foreach ($xpath->query('//file') ?: [] as $sourceFile) {
        if (! $sourceFile instanceof DOMElement) {
            continue;
        }

        $name = $sourceFile->getAttribute('name');

        if (! isset($mergedFiles[$name])) {
            $project = $mergedXPath->query('//project')->item(0);

            if (! $project instanceof DOMElement) {
                throw new RuntimeException('The base Clover report has no project element.');
            }

            $mergedFile = $mergedDocument->importNode($sourceFile, true);
            $project->appendChild($mergedFile);

            if ($mergedFile instanceof DOMElement) {
                $mergedFiles[$name] = $mergedFile;
            }

            continue;
        }

        mergeFileCoverage($mergedFiles[$name], $sourceFile);
    }
}

$statementCount = 0;
$coveredStatementCount = 0;
$methodCount = 0;
$coveredMethodCount = 0;

foreach ($mergedFiles as $file) {
    [$fileMethods, $fileCoveredMethods, $fileStatements, $fileCoveredStatements] = updateFileMetrics($file);
    $methodCount += $fileMethods;
    $coveredMethodCount += $fileCoveredMethods;
    $statementCount += $fileStatements;
    $coveredStatementCount += $fileCoveredStatements;
}

updateProjectMetrics(
    $mergedXPath,
    count($mergedFiles),
    $methodCount,
    $coveredMethodCount,
    $statementCount,
    $coveredStatementCount,
);

$outputDirectory = dirname($outputPath);

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException(sprintf('Unable to create output directory [%s].', $outputDirectory));
}

$mergedDocument->formatOutput = true;

if ($mergedDocument->save($outputPath) === false) {
    throw new RuntimeException(sprintf('Unable to write merged Clover report [%s].', $outputPath));
}

$percentage = $statementCount === 0
    ? 0.0
    : ($coveredStatementCount / $statementCount) * 100;

fwrite(STDOUT, sprintf(
    "Merged %d Clover reports: %d/%d statements covered (%.2f%%).\n",
    count($inputPaths),
    $coveredStatementCount,
    $statementCount,
    $percentage,
));

if ($percentage < REQUIRED_COVERAGE_PERCENTAGE) {
    throw new RuntimeException(sprintf(
        "Coverage %.2f%% is below the required %.2f%%.\n",
        $percentage,
        REQUIRED_COVERAGE_PERCENTAGE,
    ));
}

/**
 * @return array{int, int, int, int}
 */
function updateFileMetrics(DOMElement $file): array
{
    $statements = 0;
    $coveredStatements = 0;
    $methods = 0;
    $coveredMethods = 0;

    foreach ($file->getElementsByTagName('line') as $line) {
        if (! $line instanceof DOMElement) {
            continue;
        }

        $covered = (int) $line->getAttribute('count') > 0;

        if ($line->getAttribute('type') === 'stmt') {
            $statements++;
            $coveredStatements += (int) $covered;
        }

        if ($line->getAttribute('type') === 'method') {
            $methods++;
            $coveredMethods += (int) $covered;
        }
    }

    $metrics = directChild($file, 'metrics');

    if ($metrics !== null) {
        setCoverageMetrics($metrics, $methods, $coveredMethods, $statements, $coveredStatements);
    }

    return [$methods, $coveredMethods, $statements, $coveredStatements];
}

function mergeFileCoverage(DOMElement $targetFile, DOMElement $sourceFile): void
{
    /** @var array<string, DOMElement> $targetLines */
    $targetLines = [];

    foreach ($targetFile->getElementsByTagName('line') as $line) {
        if ($line instanceof DOMElement) {
            $targetLines[lineKey($line)] = $line;
        }
    }

    foreach ($sourceFile->getElementsByTagName('line') as $sourceLine) {
        if (! $sourceLine instanceof DOMElement) {
            continue;
        }

        $key = lineKey($sourceLine);

        if (! isset($targetLines[$key])) {
            $targetFile->appendChild($targetFile->ownerDocument->importNode($sourceLine, true));

            continue;
        }

        $count = (int) $targetLines[$key]->getAttribute('count')
            + (int) $sourceLine->getAttribute('count');
        $targetLines[$key]->setAttribute('count', (string) $count);
    }
}

function lineKey(DOMElement $line): string
{
    return implode(':', [
        $line->getAttribute('num'),
        $line->getAttribute('type'),
        $line->getAttribute('name'),
    ]);
}

function updateProjectMetrics(
    DOMXPath $xpath,
    int $files,
    int $methods,
    int $coveredMethods,
    int $statements,
    int $coveredStatements,
): void {
    $metrics = $xpath->query('//project/metrics')->item(0);

    if (! $metrics instanceof DOMElement) {
        throw new RuntimeException('The base Clover report has no project metrics element.');
    }

    $metrics->setAttribute('files', (string) $files);
    setCoverageMetrics($metrics, $methods, $coveredMethods, $statements, $coveredStatements);
}

function setCoverageMetrics(
    DOMElement $metrics,
    int $methods,
    int $coveredMethods,
    int $statements,
    int $coveredStatements,
): void {
    $metrics->setAttribute('methods', (string) $methods);
    $metrics->setAttribute('coveredmethods', (string) $coveredMethods);
    $metrics->setAttribute('statements', (string) $statements);
    $metrics->setAttribute('coveredstatements', (string) $coveredStatements);
    $metrics->setAttribute('elements', (string) ($methods + $statements));
    $metrics->setAttribute('coveredelements', (string) ($coveredMethods + $coveredStatements));
}

function directChild(DOMElement $parent, string $name): ?DOMElement
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            return $child;
        }
    }

    return null;
}
