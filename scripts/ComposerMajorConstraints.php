<?php

declare(strict_types=1);

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Check exact locked release admission and require a decision when audited holds drift.
 * Registry freshness is deliberately a separate release-time audit.
 */
final class ComposerMajorConstraints
{
    private const array PARAMETERS = ['laravel' => 'laravel/framework', 'testbench' => 'orchestra/testbench'];

    /** @return list<string> */
    public static function failures(string $root): array
    {
        /** @var array{packages: list<array{name: string, version: string, replace?: array<string, string>}>, 'packages-dev': list<array{name: string, version: string, replace?: array<string, string>}>} $lock */
        $lock = self::json($root . '/composer.lock');
        $versions = [];

        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            $versions[$package['name']] = $package['version'];

            foreach ($package['replace'] ?? [] as $name => $constraint) {
                if ($constraint === 'self.version') {
                    $versions[$name] = $package['version'];
                }
            }
        }

        $failures = [];
        $declared = [];
        $parser = new VersionParser;
        $check = static function (string $path, string $name, string $constraint) use (&$failures, &$declared, $versions, $parser): void {
            $declared[$name] = true;

            try {
                $parser->parseConstraints($constraint);

                if (isset($versions[$name]) && ! Semver::satisfies($versions[$name], $constraint)) {
                    $failures[] = sprintf('%s: %s %s excludes locked %s.', $path, $name, $constraint, $versions[$name]);
                }
            } catch (UnexpectedValueException $unexpectedValueException) {
                $failures[] = sprintf('%s: %s %s: Invalid constraint: %s', $path, $name, $constraint, $unexpectedValueException->getMessage());
            }
        };

        foreach ([$root . '/composer.json', ...(glob($root . '/packages/*/composer.json') ?: [])] as $path) {
            /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $manifest */
            $manifest = self::json($path);

            foreach (['require', 'require-dev'] as $section) {
                foreach ($manifest[$section] ?? [] as $name => $constraint) {
                    $check(substr($path, strlen($root) + 1), $name, $constraint);
                }
            }
        }

        // These named CLI parameters feed the PHP dependency-preparation script.
        $parameters = [];

        foreach (self::files($root . '/.github/workflows', ['yml', 'yaml']) as $path) {
            $relative = substr($path, strlen($root) + 1);

            try {
                $workflow = Yaml::parse(self::read($path));
            } catch (ParseException $exception) {
                $failures[] = $relative . ': Invalid workflow: ' . $exception->getMessage();

                continue;
            }

            foreach ($workflow['jobs'] ?? [$workflow] as $job) {
                $definition = $job['strategy']['matrix'] ?? $job['matrix'] ?? [];

                // Test All's matrices come from this repository's deterministic producer.
                if (is_string($definition) && preg_match('/^\$\{\{\s*fromJSON\(needs\.matrix\.outputs\.(behaviour|unit|portability)\)\s*\}\}$/', $definition, $producer) === 1 && is_file($root . '/scripts/test-all/TestAllMatrix.php')) {
                    require_once $root . '/scripts/test-all/TestAllMatrix.php';
                    $definition = ['include' => TestAllMatrix::{$producer[1]}()];
                }

                $matrix = self::matrixValues($definition);

                foreach (self::strings($job) as $text) {
                    foreach (self::overrides($text, $relative, $failures, wholeValue: true) as [$name, $constraint]) {
                        foreach (self::resolve($constraint, $matrix, $relative, $failures) as $resolved) {
                            $check($relative, $name, $resolved);
                        }
                    }

                    preg_match_all('/--(laravel|testbench)=/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

                    foreach ($matches as $match) {
                        $constraint = self::value($text, $match[0][1] + strlen($match[0][0]), $relative, $failures);

                        foreach (self::resolve($constraint, $matrix, $relative, $failures) as $resolved) {
                            $parameters[$match[1][0]][] = $resolved;
                            $check($relative, self::PARAMETERS[$match[1][0]], $resolved);
                        }
                    }
                }
            }
        }

        foreach (self::files($root . '/scripts', ['php', 'sh']) as $path) {
            $relative = substr($path, strlen($root) + 1);

            foreach (self::overrides(self::read($path), $relative, $failures) as [$name, $constraint]) {
                if (str_starts_with($constraint, '$')) {
                    $parameter = substr($constraint, 1);

                    if ((self::PARAMETERS[$parameter] ?? null) !== $name || ! isset($parameters[$parameter])) {
                        $failures[] = sprintf('%s: %s %s: Unresolved constraint parameter.', $relative, $name, $constraint);

                        continue;
                    }

                    foreach ($parameters[$parameter] as $resolved) {
                        $check($relative, $name, $resolved);
                    }

                    continue;
                }

                $check($relative, $name, $constraint);
            }
        }

        /** @var list<array{name: string, heldMajor: int, reason: string, owner: string}> $exceptions */
        $exceptions = self::json($root . '/scripts/composer-major-exceptions.json');

        foreach ($exceptions as $exception) {
            $name = $exception['name'];
            $prefix = sprintf('%s: %s', 'scripts/composer-major-exceptions.json', $name);

            if (! is_int($exception['heldMajor']) || $exception['heldMajor'] < 0 || trim($exception['reason']) === '' || trim($exception['owner']) === '') {
                $failures[] = $prefix . ': an audited hold requires a major, reason and owner.';

                continue;
            }

            if (! isset($declared[$name])) {
                $failures[] = $prefix . ' is stale; no manifest or command override declares it.';

                continue;
            }

            if (! isset($versions[$name])) {
                $failures[] = $prefix . ' has no locked version; review or remove the audited hold.';

                continue;
            }

            $major = explode('.', $parser->normalize($versions[$name]))[0];

            if ($major !== (string) $exception['heldMajor']) {
                $failures[] = sprintf('%s is held at major %d but locked %s; review or remove the audited hold.', $prefix, $exception['heldMajor'], $versions[$name]);
            }
        }

        sort($failures);

        return array_values(array_unique($failures));
    }

    /**
     * Tokenise package arguments without interpreting any constraint syntax.
     * Quotes retain spaces, comparisons, stability flags, unions and hyphen ranges.
     *
     * @param  list<string>  $failures
     * @return list<array{string, string}>
     */
    private static function overrides(string $text, string $path, array &$failures, bool $wholeValue = false): array
    {
        preg_match_all('/(?<![a-z0-9_.\/@-])([a-z0-9_.-]+\/[a-z0-9_.-]+):/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $overrides = [];

        foreach ($matches as $match) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $quote = $match[0][1] > 0 ? $text[$match[0][1] - 1] : '';

            if ($wholeValue && $match[0][1] === 0) {
                $constraint = trim(substr($text, $offset));
            } else {
                $constraint = self::value($text, $offset, $path, $failures, in_array($quote, ['"', "'"], true) ? $quote : '');
            }

            // A PHP prefix concatenated with a named parameter is not a literal override.
            if ($constraint === '' && preg_match('/^["\']\s*\.\s*(\$[a-z_]+)\b/', substr($text, $offset), $parameter) === 1) {
                $constraint = $parameter[1];
            }

            // A trailing documentation path colon is prose, not a package argument.
            if ($constraint === '' && str_ends_with($match[1][0], '.md')) {
                continue;
            }

            $overrides[] = [$match[1][0], $constraint];
        }

        return $overrides;
    }

    /** @param list<string> $failures */
    private static function value(string $text, int $offset, string $path, array &$failures, string $quote = ''): string
    {
        if ($quote === '' && in_array($text[$offset] ?? '', ['"', "'"], true)) {
            $quote = $text[$offset];
            $offset++;
        }

        $start = $offset;
        $length = strlen($text);

        while ($offset < $length) {
            $character = $text[$offset];

            if ($quote !== '' && $character === $quote) {
                return trim(substr($text, $start, $offset - $start));
            }

            if ($quote === '' && (ctype_space($character) || str_contains('"\'\\);]}', $character))) {
                return trim(substr($text, $start, $offset - $start));
            }

            if ($quote !== '' && $character === '\\') {
                $offset++;
            }

            $offset++;
        }

        if ($quote !== '') {
            $failures[] = sprintf('%s: Unterminated constraint argument: %s', $path, substr($text, max(0, $start - 80)));
        }

        return trim(substr($text, $start));
    }

    /**
     * @param  array<string, list<string>>  $matrix
     * @param  list<string>  $failures
     * @return list<string>
     */
    private static function resolve(string $constraint, array $matrix, string $path, array &$failures): array
    {
        if (! str_contains($constraint, '${')) {
            return [$constraint];
        }

        if (preg_match('/^\$\{\{\s*matrix\.([a-zA-Z0-9_-]+)\s*\}\}$/', $constraint, $match) === 1 && isset($matrix[$match[1]])) {
            return $matrix[$match[1]];
        }

        $failures[] = sprintf('%s: Unresolved constraint: %s', $path, $constraint);

        return [];
    }

    /** @return array<string, list<string>> */
    private static function matrixValues(mixed $matrix): array
    {
        $values = [];

        if (! is_array($matrix)) {
            return $values;
        }

        foreach ($matrix as $key => $value) {
            if ($key === 'include') {
                foreach ($value as $row) {
                    foreach (self::matrixValues($row) as $field => $constraints) {
                        $values[$field] = [...($values[$field] ?? []), ...$constraints];
                    }
                }

                continue;
            }

            if ($key === 'exclude') {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $constraint) {
                if (is_scalar($constraint)) {
                    $values[$key][] = (string) $constraint;
                }
            }
        }

        return $values;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        $strings = [];

        if (is_array($value)) {
            foreach ($value as $child) {
                $strings = [...$strings, ...self::strings($child)];
            }
        }

        return $strings;
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private static function files(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    /** @return array<array-key, mixed> */
    private static function json(string $path): array
    {
        try {
            $data = json_decode(self::read($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException($path . ': ' . $jsonException->getMessage(), $jsonException->getCode(), previous: $jsonException);
        }

        if (! is_array($data)) {
            throw new RuntimeException($path . ': Expected a JSON object or array.');
        }

        return $data;
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
