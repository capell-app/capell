<?php

declare(strict_types=1);

use Capell\Core\Support\Packages\TrustedCorePackages;
use PHPUnit\Framework\Assert;

/**
 * Foundation source may name only the foundation aggregate. A package that wants Core to treat it
 * differently declares a role, a default selection or install metadata in its manifest, so Core
 * finds it by what it does and never by its Composer name.
 *
 * @param  list<string>  $roots
 * @return list<string> `path:line names vendor/package` for every non-aggregate `capell-app/` name
 */
function nonAggregatePackageNamesIn(array $roots): array
{
    $offenders = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var list<SplFileInfo> $phpFiles */
        $phpFiles = array_filter(
            iterator_to_array($iterator, false),
            static fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php',
        );

        foreach ($phpFiles as $file) {
            foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                preg_match_all('~capell-app/[a-z0-9][a-z0-9-]*~', $line, $matches);

                foreach ($matches[0] as $name) {
                    if (! TrustedCorePackages::contains($name)) {
                        $offenders[] = basename(dirname($file->getPathname())) . '/' . $file->getFilename() . ':' . ($index + 1) . ' names ' . $name;
                    }
                }
            }
        }
    }

    return $offenders;
}

it('names only foundation aggregate packages in Core and Admin source', function (): void {
    $offenders = nonAggregatePackageNamesIn(array_filter([
        dirname(__DIR__, 2) . '/src',
        dirname(__DIR__, 3) . '/admin/src',
    ], is_dir(...)));

    Assert::assertSame(
        [],
        $offenders,
        "Core and Admin must not name optional packages. Declare a role, `defaultSelected` or `install` metadata in the package manifest and read it through PackageData instead:\n" . implode("\n", $offenders),
    );
});

it('reports a non-aggregate package name and accepts aggregate members', function (): void {
    $directory = sys_get_temp_dir() . '/capell-arch-neutrality-' . bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        file_put_contents($directory . '/Allowed.php', "<?php\n\$a = 'capell-app/core';\n\$b = 'capell-app/admin';\n");
        file_put_contents($directory . '/Leaky.php', "<?php\n\$a = 'capell-app/core';\n\$b = ['capell-app/theme-foundation', 'capell-app/core'];\n");
        file_put_contents($directory . '/notes.md', "capell-app/ignored-because-not-php\n");

        Assert::assertSame(
            [basename($directory) . '/Leaky.php:3 names capell-app/theme-foundation'],
            nonAggregatePackageNamesIn([$directory]),
        );
    } finally {
        array_map(unlink(...), glob($directory . '/*') ?: []);
        rmdir($directory);
    }
});

it('keeps every default install selection inside the foundation aggregate', function (): void {
    foreach (TrustedCorePackages::defaultInstallSelectionNames() as $name) {
        Assert::assertTrue(TrustedCorePackages::contains($name), $name . ' is pre-selected but is not an aggregate member.');
    }
});
