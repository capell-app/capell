<?php

declare(strict_types=1);

/**
 * Every class a Core author guide names must exist. Only namespaces the monorepo owns are judged:
 * the optional packages' namespaces, vendor examples and placeholder namespaces are left alone.
 *
 * @return list<string> "file:line Fully\Qualified\Name" for each reference that does not resolve
 */
function capellDocsMissingClassReferences(string $root): array
{
    $prefixes = [];

    foreach (glob($root . '/packages/*/composer.json') ?: [] as $composerFile) {
        $composer = json_decode((string) file_get_contents($composerFile), true);
        $psr4 = is_array($composer) ? ($composer['autoload']['psr-4'] ?? []) : [];

        foreach (is_array($psr4) ? $psr4 : [] as $namespace => $directory) {
            if (is_string($namespace) && is_string($directory) && ! str_contains($directory, 'tests')) {
                $prefixes[$namespace] = dirname($composerFile) . '/' . trim($directory, '/');
            }
        }
    }

    $files = [
        ...(glob($root . '/docs/{,*/,*/*/}*.md', GLOB_BRACE) ?: []),
        ...(glob($root . '/packages/*/{docs/,docs/*/,}*.md', GLOB_BRACE) ?: []),
    ];
    $missing = [];

    foreach (array_unique($files) as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $index => $line) {
            preg_match_all('/(?<![A-Za-z0-9_\\\\])Capell(?:\\\\{1,2}[A-Za-z]\w*)+/', $line, $matches);

            foreach ($matches[0] as $reference) {
                $name = str_replace('\\\\', '\\', $reference);

                if (! capellDocsReferenceResolves($name, $prefixes)) {
                    $missing[] = substr($file, strlen($root) + 1) . ':' . ($index + 1) . ' ' . $name;
                }
            }
        }
    }

    return array_values(array_unique($missing));
}

/** @param array<string, string> $prefixes */
function capellDocsReferenceResolves(string $name, array $prefixes): bool
{
    foreach ($prefixes as $namespace => $directory) {
        if (! str_starts_with($name . '\\', $namespace)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($name, strlen($namespace)));

        return $relative === '' || is_file($directory . '/' . $relative . '.php') || is_dir($directory . '/' . $relative);
    }

    return true;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = getenv('CAPELL_DOCS_CLASSES_ROOT') ?: dirname(__DIR__);
    $missing = capellDocsMissingClassReferences($root);

    if ($missing !== []) {
        throw new RuntimeException("Documentation names classes that do not exist:\n- " . implode("\n- ", $missing));
    }

    fwrite(STDOUT, "Every Capell class named in the documentation resolves.\n");
}
