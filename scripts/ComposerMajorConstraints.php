<?php

declare(strict_types=1);

/**
 * Catch bounded major pins without requiring an installed dependency tree.
 * Exact release admission remains covered by the Composer Semver contract test.
 */
final class ComposerMajorConstraints
{
    /** @return list<string> */
    public static function failures(string $root): array
    {
        /** @var array{packages: list<array{name: string, version: string, replace?: array<string, string>}>, 'packages-dev': list<array{name: string, version: string, replace?: array<string, string>} >} $lock */
        $lock = json_decode(self::read($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $majors = [];

        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            if (preg_match('/^v?(\d+)\.\d+(?:\.\d+)?$/', $package['version'], $matches) !== 1) {
                continue;
            }

            $major = (int) $matches[1];
            $majors[$package['name']] = max($majors[$package['name']] ?? 0, $major);

            foreach ($package['replace'] ?? [] as $name => $constraint) {
                if ($constraint === 'self.version') {
                    $majors[$name] = max($majors[$name] ?? 0, $major);
                }
            }
        }

        $failures = [];

        foreach ([$root . '/composer.json', ...(glob($root . '/packages/*/composer.json') ?: [])] as $path) {
            /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $manifest */
            $manifest = json_decode(self::read($path), true, flags: JSON_THROW_ON_ERROR);

            foreach (['require', 'require-dev'] as $section) {
                foreach ($manifest[$section] ?? [] as $name => $constraint) {
                    self::check($failures, $majors, substr($path, strlen($root) + 1), $name, $constraint);
                }
            }
        }

        // CI and provisioning commands can override otherwise correct manifests.
        foreach (['.github/workflows', 'scripts'] as $directory) {
            if (! is_dir($root . '/' . $directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo) {
                    continue;
                }

                if (! $file->isFile()) {
                    continue;
                }

                if (! in_array($file->getExtension(), ['php', 'sh', 'yml', 'yaml'], true)) {
                    continue;
                }

                $path = $file->getPathname();
                preg_match_all('/([a-z0-9_.-]+\/[a-z0-9_.-]+):((?:\^|~)?\d+(?:\.(?:\d+|[xX*])){0,2}(?:\s*\|\|?\s*(?:\^|~)?\d+(?:\.(?:\d+|[xX*])){0,2})*)/', self::read($path), $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    self::check($failures, $majors, substr($path, strlen($root) + 1), $match[1], $match[2]);
                }
            }
        }

        sort($failures);

        return array_values(array_unique($failures));
    }

    /**
     * @param  list<string>  $failures
     * @param  array<string, int>  $majors
     */
    private static function check(array &$failures, array $majors, string $path, string $name, string $constraint): void
    {
        if (! isset($majors[$name])) {
            return;
        }

        $branches = preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [];
        $admitted = [];

        foreach ($branches as $branch) {
            // Open ranges, branches and aliases need the full Semver check.
            if (preg_match('/^(?:\^|~)?(\d+)(?:\.(?:\d+|[xX*])){0,2}$/', $branch, $matches) !== 1) {
                return;
            }

            $admitted[] = (int) $matches[1];
        }

        if (! in_array($majors[$name], $admitted, true)) {
            $failures[] = sprintf('%s: %s %s excludes locked stable major %d.', $path, $name, $constraint, $majors[$name]);
        }
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $path . '.');
        }

        return $contents;
    }
}
