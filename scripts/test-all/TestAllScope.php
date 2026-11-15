<?php

declare(strict_types=1);

/**
 * Narrows the package cells of the Test All matrix to the packages a change can affect.
 *
 * The decision is deliberately one-sided: any change outside the known package
 * directories, any change to Core (which every package requires) and any change that
 * cannot be listed selects the whole matrix. Only a change confined to leaf packages
 * narrows it, to those packages plus every package that requires them.
 */
final class TestAllScope
{
    /**
     * @param  list<string>  $changedFiles  repository-relative paths
     * @param  array<string, list<string>>  $requiredBy  package directory => directories of the packages that require it
     * @return list<string>|null package directories to test, or null for every package
     */
    public static function packages(array $changedFiles, array $requiredBy): ?array
    {
        if ($changedFiles === []) {
            return null;
        }

        $known = array_unique([...array_keys($requiredBy), ...array_merge(...array_values($requiredBy))]);
        $selected = [];

        foreach ($changedFiles as $file) {
            if (preg_match('#^packages/([^/]+)/#', $file, $match) !== 1 || ! in_array($match[1], $known, true) || $match[1] === 'core') {
                return null;
            }

            $selected[$match[1]] = true;
        }

        $queue = array_keys($selected);

        while ($queue !== []) {
            $package = array_shift($queue);

            foreach ($requiredBy[$package] ?? [] as $dependent) {
                if (! isset($selected[$dependent])) {
                    $selected[$dependent] = true;
                    $queue[] = $dependent;
                }
            }
        }

        $packages = array_keys($selected);
        sort($packages);

        return $packages;
    }

    /**
     * @return array<string, list<string>> package directory => directories of the packages that require it
     */
    public static function requiredBy(string $root): array
    {
        $names = [];
        $requires = [];

        foreach (glob($root . '/packages/*/composer.json') ?: [] as $path) {
            $directory = basename(dirname($path));
            $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $names[(string) $composer['name']] = $directory;
            $requires[$directory] = array_keys([...($composer['require'] ?? []), ...($composer['require-dev'] ?? [])]);
        }

        $requiredBy = array_fill_keys(array_values($names), []);

        foreach ($requires as $directory => $packages) {
            foreach ($packages as $package) {
                if (isset($names[$package])) {
                    $requiredBy[$names[$package]][] = $directory;
                }
            }
        }

        return $requiredBy;
    }

    /**
     * @return list<string>
     */
    public static function changedFiles(string $root, string $base, string $head): array
    {
        $output = [];
        $status = 0;
        exec(sprintf('git -C %s diff --name-only %s %s 2>/dev/null', escapeshellarg($root), escapeshellarg($base), escapeshellarg($head)), $output, $status);

        // An undecidable diff selects the full matrix.
        return $status === 0 ? array_values(array_filter($output, static fn (string $line): bool => $line !== '')) : [];
    }

    /**
     * Keeps every cell that is not a per-package cell, and per-package cells of the selected packages.
     *
     * @param  list<array<string, string>>  $cells
     * @param  list<string>|null  $packages
     * @param  list<string>  $packageGroups  every package directory the matrix has cells for
     * @return list<array<string, string>>
     */
    public static function filter(array $cells, ?array $packages, array $packageGroups): array
    {
        if ($packages === null) {
            return $cells;
        }

        return array_values(array_filter(
            $cells,
            static fn (array $cell): bool => ! in_array($cell['test_group'] ?? '', $packageGroups, true) || in_array($cell['test_group'], $packages, true),
        ));
    }
}
