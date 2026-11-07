<?php

declare(strict_types=1);

/**
 * Records the bytes of every create migration Capell has shipped, so ownership checks can
 * authenticate a retained publication after its source changes. Existing entries are kept.
 * Bytes from rewritten history survive only in those entries, so never regenerate from empty.
 * Run after adding or editing a create migration; TableNameMigrationHistoryTest detects drift.
 */
$root = dirname(__DIR__);
chdir($root);

/** @return list<string> */
$git = static function (string ...$arguments): array {
    $command = 'git ' . implode(' ', array_map(escapeshellarg(...), $arguments));
    exec($command, $output, $exit);
    if ($exit !== 0) {
        throw new RuntimeException('Git failed: ' . $command);
    }

    return $output;
};

$packages = ['admin', 'core', 'frontend', 'installer', 'marketplace'];
$references = $git('tag', '--list');

foreach ($packages as $package) {
    $directory = 'packages/' . $package . '/database/migrations';
    $target = 'packages/' . $package . '/database/migration-history.json';
    $history = is_file($target) ? json_decode((string) file_get_contents($target), true, flags: JSON_THROW_ON_ERROR) : [];
    $blobs = [];

    foreach ($references as $reference) {
        foreach ($git('ls-tree', '-r', $reference, '--', $directory . '/') as $line) {
            [$metadata, $path] = explode("\t", $line, 2);
            $blobs[explode(' ', $metadata)[2]][basename($path, '.php')] = true;
        }
    }

    // Published copies can predate any tag, so walk every revision of the directory as well.
    foreach ($git('log', '--full-history', '-m', '--raw', '--no-abbrev', '--no-renames', '--format=', '--diff-filter=AM', 'HEAD', '--', $directory . '/') as $line) {
        [$metadata, $path] = explode("\t", $line, 2);
        $blobs[explode(' ', $metadata)[3]][basename($path, '.php')] = true;
    }

    $record = static function (string $file, string $sha256) use (&$history): void {
        if (! str_contains($file, 'create_')) {
            return;
        }

        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $file) ?? $file;
        $history[$name] ??= ['names' => [], 'sha256' => []];
        if ($name !== $file) {
            $history[$name]['names'][] = $file;
        }

        $history[$name]['sha256'][] = $sha256;
    };

    foreach ($blobs as $blob => $files) {
        $contents = shell_exec('git cat-file blob ' . escapeshellarg($blob));
        foreach (is_string($contents) ? array_keys($files) : [] as $file) {
            $record((string) $file, hash('sha256', (string) $contents));
        }
    }

    foreach (glob($directory . '/*.php') ?: [] as $path) {
        $record(basename($path, '.php'), (string) hash_file('sha256', $path));
    }

    if ($history === []) {
        continue;
    }

    ksort($history);
    foreach ($history as $name => $entry) {
        $names = array_values(array_unique($entry['names']));
        $hashes = array_values(array_unique($entry['sha256']));
        sort($names);
        sort($hashes);
        $history[$name] = ['names' => $names, 'sha256' => $hashes];
    }

    file_put_contents($target, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
