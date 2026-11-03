<?php

declare(strict_types=1);

/**
 * Refuse to run vendor-mutating commands when vendor/ resolves outside this
 * checkout.
 *
 * scripts/init-worktree.sh builds a hybrid vendor/ for git worktrees: real
 * copies of vendor/composer, vendor/autoload.php and vendor/bin, with the
 * remaining third-party packages symlinked at one level deeper into the
 * primary checkout. That makes a worktree usable in seconds, but it also means
 * anything writing into a symlinked package directory writes into the PRIMARY
 * checkout, where another session may be mid-run.
 *
 * `composer clear` deletes vendor/orchestra/testbench-core/laravel/vendor and
 * .../database/migrations, and `composer prepare` regenerates the testbench
 * skeleton in the same place. Through a symlink both reach across checkouts.
 *
 * This guard fails those commands with an actionable message instead.
 */
$root = getenv('CAPELL_VENDOR_INTEGRITY_ROOT') ?: dirname(__DIR__);
$realRoot = realpath($root);

if (! is_string($realRoot)) {
    fwrite(STDERR, sprintf("Unable to resolve the repository root at %s.\n", $root));

    exit(2);
}

if (! is_dir($realRoot . '/vendor')) {
    fwrite(STDERR, "vendor/ is missing. Run 'composer install' before this command.\n");

    exit(2);
}

/**
 * Paths that `composer clear` and `composer prepare` write into or delete.
 *
 * @var list<string> $mutatedPaths
 */
$mutatedPaths = [
    'vendor',
    'vendor/composer',
    'vendor/orchestra/testbench-core',
    'vendor/composer/autoload_real.php',
    'vendor/orchestra/testbench-core/laravel/vendor',
];

$failures = [];

foreach ($mutatedPaths as $relativePath) {
    $absolutePath = $realRoot . '/' . $relativePath;

    if (! file_exists($absolutePath) && ! is_link($absolutePath)) {
        if ($relativePath === 'vendor/composer/autoload_real.php') {
            $failures[] = sprintf('%s is missing.', $relativePath);
        }

        continue;
    }

    $resolvedPath = realpath($absolutePath);

    if (! is_string($resolvedPath)) {
        $failures[] = sprintf('%s is a broken symlink.', $relativePath);

        continue;
    }

    if (str_starts_with(normalisePath($resolvedPath) . DIRECTORY_SEPARATOR, normalisePath($realRoot) . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $failures[] = sprintf('%s resolves to %s.', $relativePath, $resolvedPath);
}

if ($failures !== []) {
    fwrite(STDERR, "Refusing to run: vendor/ is not owned by this checkout.\n\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf('- %s%s', $failure, PHP_EOL));
    }

    fwrite(STDERR, <<<'MESSAGE'

        Repair this checkout with a real 'composer install'. For a host-only
        worktree, rebuild its vendor with 'bash scripts/init-worktree.sh --host-only --force'.

        MESSAGE);

    exit(2);
}

fwrite(STDOUT, "Vendor integrity is verified.\n");

function normalisePath(string $path): string
{
    $normalised = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

    return PHP_OS_FAMILY === 'Windows' ? strtolower($normalised) : $normalised;
}
