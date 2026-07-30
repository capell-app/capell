<?php

declare(strict_types=1);

/**
 * Enforce the queued-work reliability contract documented in
 * docs/standards/queue-reliability.md.
 *
 * Laravel's default is a single attempt with no backoff, so a queued class that
 * declares nothing loses its work permanently on the first transient failure.
 * This gate scans queued work classes (jobs and queued listeners) and reports
 * every class that does not declare the reliability knobs the contract requires.
 *
 * Existing debt is recorded in scripts/queue-contract-baseline.json so the gate
 * lands green and fails only on new violations. Regenerate the baseline with
 * --update; the baseline is expected to shrink, never grow.
 *
 * Usage:
 *   php scripts/check-queue-contract.php [--update] [--strict] [--format=text|json] [--root=packages]
 */

/** @var array<string, string> $queueContractRules */
$queueContractRules = [
    'QUEUE001' => 'declare a retry budget: public int $tries (2 or more, or 0 with a reason), tries(), or retryUntil()',
    'QUEUE002' => 'pair a retry budget above one attempt with $backoff, backoff(), or retryUntil()',
    'QUEUE003' => 'implement failed(?Throwable $exception): void so exhausted attempts are observable',
    'QUEUE004' => 'queued listeners fire per model save, so implement ShouldBeUnique with uniqueId(), or apply WithoutOverlapping',
    'QUEUE005' => 'declare public int $timeout when the class performs an outbound HTTP or process call',
];

/**
 * Parse the supported command-line flags.
 *
 * @param  list<string>  $arguments
 * @return array{update: bool, strict: bool, format: string, root: string}
 */
function queueContractOptions(array $arguments): array
{
    $options = [
        'update' => false,
        'strict' => false,
        'format' => 'text',
        'root' => 'packages',
    ];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--update') {
            $options['update'] = true;

            continue;
        }

        if ($argument === '--strict') {
            $options['strict'] = true;

            continue;
        }

        if ($argument === '--check') {
            continue; // Verifying is the default; accepted for symmetry with the other check scripts.
        }

        if (str_starts_with($argument, '--format=')) {
            $options['format'] = substr($argument, strlen('--format='));

            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $options['root'] = substr($argument, strlen('--root='));

            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            fwrite(STDOUT, "Usage: php scripts/check-queue-contract.php [--update] [--strict] [--format=text|json] [--root=packages]\n");

            exit(0);
        }

        fwrite(STDERR, sprintf('Unknown argument: %s%s', $argument, PHP_EOL));

        exit(1);
    }

    if (! in_array($options['format'], ['text', 'json'], true)) {
        fwrite(STDERR, sprintf('Unsupported format: %s. Use text or json.%s', $options['format'], PHP_EOL));

        exit(1);
    }

    return $options;
}

/**
 * Collect every PHP file beneath the analysis root, skipping build output.
 *
 * @return list<string>
 */
function queueContractSourceFiles(string $rootPath): array
{
    $skippedDirectories = ['.git', '.phpunit.cache', 'coverage', 'node_modules', 'storage', 'vendor'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $candidate): bool => ! $candidate->isDir() || ! in_array($candidate->getFilename(), $skippedDirectories, true),
        ),
    );

    $sourceFiles = [];

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo instanceof SplFileInfo) {
            continue;
        }

        if (! $fileInfo->isFile()) {
            continue;
        }

        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }

        if (str_ends_with($fileInfo->getFilename(), '.blade.php')) {
            continue;
        }

        $sourceFiles[] = $fileInfo->getPathname();
    }

    sort($sourceFiles);

    return $sourceFiles;
}

/**
 * Decide whether a queued class is queued work this contract governs.
 *
 * Notifications and mailables are queued too, but their delivery, retry, and
 * failure reporting are owned by the mail/notification transport rather than by
 * the class itself, so they are deliberately out of scope for this gate.
 */
function queueContractGovernsPath(string $relativePath, string $className): bool
{
    if (str_contains($relativePath, '/src/Notifications/') || str_contains($relativePath, '/src/Mail/')) {
        return false;
    }

    return str_contains($relativePath, '/src/Jobs/')
        || str_contains($relativePath, '/src/Listeners/')
        || str_ends_with($className, 'Job');
}

/**
 * Read the exemptions declared in a file as `@queue-contract-exempt QUEUE00X reason`.
 *
 * @return array<string, string>
 */
function queueContractExemptions(string $contents): array
{
    if (preg_match_all('/@queue-contract-exempt\s+(QUEUE\d{3})\s+(\S.*?)\s*$/m', $contents, $exemptionMatches, PREG_SET_ORDER) === 0) {
        return [];
    }

    $exemptions = [];

    foreach ($exemptionMatches as $exemptionMatch) {
        $exemptions[$exemptionMatch[1]] = rtrim($exemptionMatch[2], " \t*");
    }

    return $exemptions;
}

/**
 * Remove comments and string literals while preserving executable PHP tokens.
 */
function queueContractCodeOnly(string $contents): string
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
        if (is_string($token)) {
            $code .= $token;

            continue;
        }

        [$id, $text] = $token;
        $code .= in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)
            ? str_repeat(' ', strlen($text))
            : $text;
    }

    return $code;
}

function queueContractCallsExternalService(string $contents, string $code): bool
{
    if (preg_match('/(?:\bHttp::|\bProcess::|\bproc_open\s*\(|\bshell_exec\s*\(|\bcurl_init\s*\()/', $code) === 1) {
        return true;
    }

    $tokens = token_get_all($contents);

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] !== T_STRING) {
            continue;
        }

        if (strtolower($token[1]) !== 'file_get_contents') {
            continue;
        }

        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $candidate = $tokens[$cursor];

            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($candidate) && $candidate[0] === T_CONSTANT_ENCAPSED_STRING) {
                return preg_match('/^[\'"]https?:/i', $candidate[1]) === 1;
            }

            if ($candidate !== '(') {
                break;
            }
        }
    }

    return false;
}

/**
 * Describe the reliability knobs a queued class declares.
 *
 * @return array{tries: ?int, unlimitedTriesHasReason: bool, hasTriesMethod: bool, hasBackoff: bool, hasRetryUntil: bool, hasFailed: bool, hasTimeout: bool, hasUniqueContract: bool, hasUniqueId: bool, hasWithoutOverlapping: bool, hasUpstreamDebounce: bool, callsExternalService: bool}
 */
function queueContractDeclarations(string $contents, bool $isListener): array
{
    $code = queueContractCodeOnly($contents);
    $triesLiteral = null;

    if (preg_match('/public\s+int\s+\$tries\s*=\s*(\d+)\s*;/', $code, $triesMatch) === 1) {
        $triesLiteral = (int) $triesMatch[1];
    }

    return [
        'tries' => $triesLiteral,
        'unlimitedTriesHasReason' => $triesLiteral !== 0
            || preg_match('/\/\*\*[\s\S]*?(?:unlimited|deadline|retryUntil|operator)[\s\S]*?\*\/\s*public\s+int\s+\$tries\s*=\s*0\s*;/i', $contents) === 1,
        'hasTriesMethod' => preg_match('/public\s+function\s+tries\s*\([^)]*\)\s*:\s*int\b/', $code) === 1,
        'hasBackoff' => preg_match('/public\s+(?:int|array)\s+\$backoff\s*=/', $code) === 1
            || preg_match('/public\s+function\s+backoff\s*\([^)]*\)\s*:\s*(?:int|array)\b/', $code) === 1,
        'hasRetryUntil' => preg_match('/public\s+function\s+retryUntil\s*\([^)]*\)\s*:\s*(?:\\\\?DateTimeInterface|\\\\?DateTimeImmutable|\\\\?Carbon(?:Immutable)?)\b/', $code) === 1,
        'hasFailed' => preg_match(
            $isListener
                ? '/public\s+function\s+failed\s*\([^,]+,\s*\?\\\\?Throwable\s+\$\w+\s*\)\s*:\s*void\b/'
                : '/public\s+function\s+failed\s*\(\s*\?\\\\?Throwable\s+\$\w+\s*\)\s*:\s*void\b/',
            $code,
        ) === 1,
        'hasTimeout' => preg_match('/public\s+int\s+\$timeout\s*=/', $code) === 1,
        'hasUniqueContract' => preg_match('/\bimplements\b[^{]*?\bShouldBeUnique\b/s', $code) === 1,
        'hasUniqueId' => preg_match('/public\s+function\s+uniqueId\s*\([^)]*\)\s*:\s*string\b/', $code) === 1,
        'hasWithoutOverlapping' => preg_match('/public\s+function\s+middleware\s*\([^)]*\)\s*:\s*array\b[\s\S]*?return\s*\[[^\]]*new\s+WithoutOverlapping\s*\(/', $code) === 1,
        'hasUpstreamDebounce' => preg_match('/@queue-contract-upstream-debounce\s+\S.*$/m', $contents) === 1,
        'callsExternalService' => queueContractCallsExternalService($contents, $code),
    ];
}

/**
 * Scan the analysis root and return every queue-contract violation it finds.
 *
 * @return array{violations: list<array{id: string, rule: string, path: string, class: string, detail: string}>, scanned: int, exempted: int}
 */
function queueContractViolations(string $repositoryRoot, string $rootPath): array
{
    $violations = [];
    $scannedClasses = 0;
    $exemptedRules = 0;

    foreach (queueContractSourceFiles($rootPath) as $sourceFile) {
        $contents = file_get_contents($sourceFile);

        if ($contents === false) {
            fwrite(STDERR, sprintf('Unable to read %s.%s', $sourceFile, PHP_EOL));

            exit(1);
        }

        $code = queueContractCodeOnly($contents);

        if (! str_contains($code, 'ShouldQueue')) {
            continue;
        }

        if (preg_match('/\b(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)[^{]*?\bimplements\b[^{]*?\bShouldQueue\b/s', $code, $classMatch) !== 1) {
            continue;
        }

        if (preg_match('/\babstract\s+class\s+\w+/', $code) === 1) {
            continue; // The concrete subclass owns the reliability contract.
        }

        $className = $classMatch[1];
        $relativePath = str_replace($repositoryRoot . DIRECTORY_SEPARATOR, '', $sourceFile);
        $normalisedPath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if (! queueContractGovernsPath('/' . $normalisedPath, $className)) {
            continue;
        }

        $scannedClasses++;
        $isListener = str_contains('/' . $normalisedPath, '/src/Listeners/');
        $declarations = queueContractDeclarations($contents, $isListener);
        $exemptions = queueContractExemptions($contents);
        $failures = [];

        $hasRetryBudget = $declarations['hasTriesMethod']
            || $declarations['hasRetryUntil']
            || ($declarations['tries'] !== null && $declarations['tries'] !== 1 && $declarations['unlimitedTriesHasReason']);

        if (! $hasRetryBudget) {
            $failures['QUEUE001'] = $declarations['tries'] === 1
                ? 'declares $tries = 1, so one transient failure drops the work'
                : 'declares no retry budget, so it inherits the single-attempt default';
        }

        $retriesMoreThanOnce = $declarations['hasTriesMethod']
            || ($declarations['tries'] !== null && $declarations['tries'] !== 1);

        if ($retriesMoreThanOnce && ! $declarations['hasBackoff'] && ! $declarations['hasRetryUntil']) {
            $failures['QUEUE002'] = 'retries without backoff, so retries hammer the same failing dependency';
        }

        if (! $declarations['hasFailed']) {
            $failures['QUEUE003'] = 'has no failed() handler, so exhausted attempts leave no operational trace';
        }

        if ($isListener && (! $declarations['hasUniqueContract'] || ! $declarations['hasUniqueId']) && ! $declarations['hasWithoutOverlapping'] && ! $declarations['hasUpstreamDebounce']) {
            $failures['QUEUE004'] = 'is a queued listener with no dedupe, so a bulk edit multiplies identical jobs';
        }

        if ($declarations['callsExternalService'] && ! $declarations['hasTimeout']) {
            $failures['QUEUE005'] = 'calls an external service without $timeout, so a hung call occupies a worker indefinitely';
        }

        foreach ($failures as $ruleId => $detail) {
            if (isset($exemptions[$ruleId])) {
                $exemptedRules++;

                continue;
            }

            $violations[] = [
                'id' => sprintf('%s::%s', $normalisedPath, $ruleId),
                'rule' => $ruleId,
                'path' => $normalisedPath,
                'class' => $className,
                'detail' => $detail,
            ];
        }
    }

    usort($violations, static fn (array $first, array $second): int => strcmp($first['id'], $second['id']));

    return [
        'violations' => $violations,
        'scanned' => $scannedClasses,
        'exempted' => $exemptedRules,
    ];
}

/**
 * Read the recorded debt baseline.
 *
 * @return list<string>
 */
function queueContractBaseline(string $baselinePath): array
{
    if (! is_file($baselinePath)) {
        return [];
    }

    $baselineContents = file_get_contents($baselinePath);

    if ($baselineContents === false) {
        fwrite(STDERR, sprintf('Unable to read %s.%s', $baselinePath, PHP_EOL));

        exit(1);
    }

    $baseline = json_decode($baselineContents, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($baseline) || ! is_array($baseline['violations'] ?? null)) {
        fwrite(STDERR, sprintf('Malformed queue contract baseline: %s%s', $baselinePath, PHP_EOL));

        exit(1);
    }

    $identifiers = [];

    foreach ($baseline['violations'] as $entry) {
        if (is_array($entry) && is_string($entry['id'] ?? null)) {
            $identifiers[] = $entry['id'];
        }
    }

    sort($identifiers);

    return array_values(array_unique($identifiers));
}

$options = queueContractOptions($_SERVER['argv'] ?? []);
$repositoryRoot = dirname(__DIR__);
$rootPath = realpath($repositoryRoot . DIRECTORY_SEPARATOR . $options['root']) ?: realpath($options['root']);

if ($rootPath === false) {
    fwrite(STDERR, sprintf('Queue contract root does not exist: %s%s', $options['root'], PHP_EOL));

    exit(1);
}

$baselinePath = $repositoryRoot . '/scripts/queue-contract-baseline.json';
$scan = queueContractViolations($repositoryRoot, $rootPath);

if ($options['update']) {
    $written = file_put_contents($baselinePath, json_encode([
        'schemaVersion' => 1,
        'standard' => 'docs/standards/queue-reliability.md',
        'root' => $options['root'],
        'violationCount' => count($scan['violations']),
        'violations' => array_map(static fn (array $violation): array => [
            'id' => $violation['id'],
            'rule' => $violation['rule'],
            'class' => $violation['class'],
        ], $scan['violations']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

    if ($written === false) {
        fwrite(STDERR, sprintf('Unable to write %s.%s', $baselinePath, PHP_EOL));

        exit(1);
    }

    printf('Queue contract baseline recorded with %d known violation(s).%s', count($scan['violations']), PHP_EOL);

    exit(0);
}

$baselineIdentifiers = queueContractBaseline($baselinePath);
$currentIdentifiers = array_map(static fn (array $violation): string => $violation['id'], $scan['violations']);
$newIdentifiers = array_values(array_diff($currentIdentifiers, $baselineIdentifiers));
$staleIdentifiers = array_values(array_diff($baselineIdentifiers, $currentIdentifiers));
$newViolations = array_values(array_filter(
    $scan['violations'],
    static fn (array $violation): bool => in_array($violation['id'], $newIdentifiers, true),
));

if ($options['format'] === 'json') {
    echo json_encode([
        'scanned_classes' => $scan['scanned'],
        'exempted_rules' => $scan['exempted'],
        'baseline_count' => count($baselineIdentifiers),
        'current_count' => count($currentIdentifiers),
        'new_count' => count($newIdentifiers),
        'stale_count' => count($staleIdentifiers),
        'new' => $newViolations,
        'stale' => $staleIdentifiers,
        'rules' => $queueContractRules,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    printf(
        "Queue reliability contract\n==========================\nQueued work classes scanned: %d\nBaselined violations: %d\nCurrent violations: %d\nNew violations: %d\nFixed but still baselined: %d\nDeclared exemptions honoured: %d\n",
        $scan['scanned'],
        count($baselineIdentifiers),
        count($currentIdentifiers),
        count($newIdentifiers),
        count($staleIdentifiers),
        $scan['exempted'],
    );
}

if ($newViolations !== []) {
    fwrite(STDERR, sprintf('%sNew queue contract violation(s):%s', PHP_EOL, PHP_EOL));

    foreach ($newViolations as $violation) {
        fwrite(STDERR, sprintf(
            '- %s [%s] %s %s%s',
            $violation['path'],
            $violation['rule'],
            $violation['class'],
            $violation['detail'],
            PHP_EOL,
        ));
    }

    fwrite(STDERR, sprintf('%sRequired by docs/standards/queue-reliability.md:%s', PHP_EOL, PHP_EOL));

    foreach (array_unique(array_map(static fn (array $violation): string => $violation['rule'], $newViolations)) as $ruleId) {
        fwrite(STDERR, sprintf('- %s: %s%s', $ruleId, $queueContractRules[$ruleId], PHP_EOL));
    }

    fwrite(STDERR, "\nFix the queued class, or add a justified `@queue-contract-exempt QUEUE00X <reason>` docblock line. Do not run --update to absorb a new violation.\n");

    exit(2);
}

if ($staleIdentifiers !== []) {
    fwrite(STDOUT, sprintf('%sBaseline entries that no longer violate the contract:%s', PHP_EOL, PHP_EOL));

    foreach ($staleIdentifiers as $staleIdentifier) {
        fwrite(STDOUT, sprintf('- %s%s', $staleIdentifier, PHP_EOL));
    }

    fwrite(STDOUT, "\nShrink the baseline with: composer check:queue-contract -- --update\n");

    if ($options['strict']) {
        exit(2);
    }
}

if ($options['format'] === 'text') {
    fwrite(STDOUT, "\nNo new queue contract violations.\n");
}

exit(0);
