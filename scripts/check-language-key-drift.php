<?php

declare(strict_types=1);

/**
 * Gate translation-key drift.
 *
 * scripts/audit-language-keys.sh already reports unused and missing static
 * `capell-*::group.key` translation keys, but it is advisory: non-strict runs
 * always exit 0, and the repository carries a large historical backlog, so
 * running it strictly would fail on day one. This wrapper records that backlog
 * in scripts/language-keys-baseline.json and fails only on keys that are not in
 * the baseline, so new drift is caught while existing debt stays visible.
 *
 * Usage:
 *   php scripts/check-language-key-drift.php [--update] [--strict] [--format=text|json] [--root=packages]
 */

/**
 * Parse the supported command-line flags.
 *
 * @param  list<string>  $arguments
 * @return array{update: bool, strict: bool, format: string, root: string}
 */
function languageKeyDriftOptions(array $arguments): array
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
            fwrite(STDOUT, "Usage: php scripts/check-language-key-drift.php [--update] [--strict] [--format=text|json] [--root=packages]\n");

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
 * Run the underlying audit script and decode its JSON report.
 *
 * @return array<string, mixed>
 */
function languageKeyAuditReport(string $repositoryRoot, string $root): array
{
    $auditScript = $repositoryRoot . '/scripts/audit-language-keys.sh';

    if (! is_file($auditScript)) {
        fwrite(STDERR, sprintf('Missing translation audit script: %s%s', $auditScript, PHP_EOL));

        exit(1);
    }

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        ['bash', $auditScript, '--format=json', sprintf('--root=%s', $root)],
        $descriptors,
        $pipes,
        $repositoryRoot,
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "Unable to start the translation audit script.\n");

        exit(1);
    }

    $standardOutput = (string) stream_get_contents($pipes[1]);
    $standardError = (string) stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        fwrite(STDERR, sprintf('Translation audit script failed (exit %d):%s%s', $exitCode, PHP_EOL, $standardError));

        exit(1);
    }

    $report = json_decode($standardOutput, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($report) || ! is_array($report['unused'] ?? null) || ! is_array($report['missing'] ?? null)) {
        fwrite(STDERR, "Translation audit script produced an unexpected report shape.\n");

        exit(1);
    }

    return $report;
}

/**
 * Flatten the audit report into stable, comparable finding identifiers.
 *
 * @param  array<string, mixed>  $report
 * @return array{unused: list<string>, missing: list<string>}
 */
function languageKeyFindings(array $report): array
{
    $unusedKeys = [];
    $missingKeys = [];

    foreach ($report['unused'] as $entry) {
        if (is_array($entry) && is_string($entry['key'] ?? null)) {
            $unusedKeys[] = $entry['key'];
        }
    }

    foreach ($report['missing'] as $entry) {
        if (is_array($entry) && is_string($entry['key'] ?? null)) {
            $missingKeys[] = $entry['key'];
        }
    }

    sort($unusedKeys);
    sort($missingKeys);

    return [
        'unused' => array_values(array_unique($unusedKeys)),
        'missing' => array_values(array_unique($missingKeys)),
    ];
}

/**
 * Read the recorded translation-key debt baseline.
 *
 * @return array{unused: list<string>, missing: list<string>}
 */
function languageKeyBaseline(string $baselinePath): array
{
    if (! is_file($baselinePath)) {
        return ['unused' => [], 'missing' => []];
    }

    $baselineContents = file_get_contents($baselinePath);

    if ($baselineContents === false) {
        fwrite(STDERR, sprintf('Unable to read %s.%s', $baselinePath, PHP_EOL));

        exit(1);
    }

    $baseline = json_decode($baselineContents, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($baseline) || ! is_array($baseline['unused'] ?? null) || ! is_array($baseline['missing'] ?? null)) {
        fwrite(STDERR, sprintf('Malformed translation key baseline: %s%s', $baselinePath, PHP_EOL));

        exit(1);
    }

    return [
        'unused' => array_values(array_filter($baseline['unused'], is_string(...))),
        'missing' => array_values(array_filter($baseline['missing'], is_string(...))),
    ];
}

/**
 * Report a finding category and return the offending keys.
 *
 * @param  list<string>  $keys
 */
function languageKeyReportSection(string $heading, array $keys, string $guidance): void
{
    fwrite(STDERR, sprintf('%s%s (%d):%s', PHP_EOL, $heading, count($keys), PHP_EOL));

    foreach (array_slice($keys, 0, 50) as $key) {
        fwrite(STDERR, sprintf('- %s%s', $key, PHP_EOL));
    }

    $remaining = count($keys) - min(50, count($keys));

    if ($remaining > 0) {
        fwrite(STDERR, sprintf('...and %d more.%s', $remaining, PHP_EOL));
    }

    fwrite(STDERR, sprintf('%s%s', $guidance, PHP_EOL));
}

$options = languageKeyDriftOptions($_SERVER['argv'] ?? []);
$repositoryRoot = dirname(__DIR__);
$baselinePath = $repositoryRoot . '/scripts/language-keys-baseline.json';
$report = languageKeyAuditReport($repositoryRoot, $options['root']);
$findings = languageKeyFindings($report);

if ($options['update']) {
    $written = file_put_contents($baselinePath, json_encode([
        'schemaVersion' => 1,
        'auditScript' => 'scripts/audit-language-keys.sh',
        'root' => $options['root'],
        'unusedCount' => count($findings['unused']),
        'missingCount' => count($findings['missing']),
        'unused' => $findings['unused'],
        'missing' => $findings['missing'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

    if ($written === false) {
        fwrite(STDERR, sprintf('Unable to write %s.%s', $baselinePath, PHP_EOL));

        exit(1);
    }

    printf(
        'Translation key baseline recorded with %d unused and %d missing key(s).%s',
        count($findings['unused']),
        count($findings['missing']),
        PHP_EOL,
    );

    exit(0);
}

$baseline = languageKeyBaseline($baselinePath);
$newUnused = array_values(array_diff($findings['unused'], $baseline['unused']));
$newMissing = array_values(array_diff($findings['missing'], $baseline['missing']));
$fixedUnused = array_values(array_diff($baseline['unused'], $findings['unused']));
$fixedMissing = array_values(array_diff($baseline['missing'], $findings['missing']));

if ($options['format'] === 'json') {
    echo json_encode([
        'defined_count' => $report['defined_count'] ?? null,
        'used_static_count' => $report['used_static_count'] ?? null,
        'dynamic_count' => $report['dynamic_count'] ?? null,
        'baseline_unused' => count($baseline['unused']),
        'baseline_missing' => count($baseline['missing']),
        'current_unused' => count($findings['unused']),
        'current_missing' => count($findings['missing']),
        'new_unused' => $newUnused,
        'new_missing' => $newMissing,
        'fixed_unused' => count($fixedUnused),
        'fixed_missing' => count($fixedMissing),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    printf(
        "Translation key drift\n=====================\nRoot: %s\nDefined keys: %s\nStatic used keys: %s\nDynamic expressions to review: %s\nBaselined unused / missing: %d / %d\nCurrent unused / missing: %d / %d\nNew unused / missing: %d / %d\nFixed but still baselined: %d / %d\n",
        $options['root'],
        (string) ($report['defined_count'] ?? '?'),
        (string) ($report['used_static_count'] ?? '?'),
        (string) ($report['dynamic_count'] ?? '?'),
        count($baseline['unused']),
        count($baseline['missing']),
        count($findings['unused']),
        count($findings['missing']),
        count($newUnused),
        count($newMissing),
        count($fixedUnused),
        count($fixedMissing),
    );
}

if ($newMissing !== []) {
    languageKeyReportSection(
        'Translation keys used in code but not defined in any lang file',
        $newMissing,
        'Add the key to the owning package lang file, or correct the key in the calling code.',
    );
}

if ($newUnused !== []) {
    languageKeyReportSection(
        'Translation keys defined but never referenced by a static call',
        $newUnused,
        'Remove the dead key, or reference it from the code path that should use it. If the key is only reached dynamically, keep the call site statically analysable.',
    );
}

if ($newMissing !== [] || $newUnused !== []) {
    fwrite(STDERR, "\nDo not run --update to absorb new drift. Fix the key, then re-run this check.\n");

    exit(2);
}

if ($fixedUnused !== [] || $fixedMissing !== []) {
    printf(
        '%sBaseline entries that no longer appear: %d unused, %d missing.%sShrink the baseline with: composer check:lang-keys -- --update%s',
        PHP_EOL,
        count($fixedUnused),
        count($fixedMissing),
        PHP_EOL,
        PHP_EOL,
    );

    if ($options['strict']) {
        exit(2);
    }
}

if ($options['format'] === 'text') {
    fwrite(STDOUT, "\nNo new translation key drift.\n");
}

exit(0);
