<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

$repositoryRoot = dirname(__DIR__, 4);

/*
 * Marketplace behaviour reaches Core through typed contracts such as
 * ExtensionEntitlements. A string container key is invisible to static
 * analysis, so a key that nothing binds fails silently: licence checks once
 * looked up an unbound 'capell.marketplace.client' and fell back to an
 * unsigned request the marketplace always rejects. Route names and container
 * tags are not service lookups and stay allowed.
 */
it('keeps package source free of string marketplace container lookups', function () use ($repositoryRoot): void {
    $lookup = '/(?:\b(?:app|resolve|bound|resolved|make|makeWith|bind|bindIf|singleton|singletonIf|scoped|scopedIf|instance|extend|offsetGet|offsetExists|forgetInstance)\s*\(|\[)\s*[\'"]capell\.marketplace\./';

    $violations = collect(File::glob($repositoryRoot . '/packages/*/src'))
        ->flatMap(fn (string $directory): array => File::allFiles($directory))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->flatMap(function (SplFileInfo $file) use ($lookup, $repositoryRoot): array {
            $contents = File::get($file->getPathname());
            $relativePath = str_replace($repositoryRoot . '/', '', $file->getPathname());

            preg_match_all($lookup, $contents, $matches, PREG_OFFSET_CAPTURE);

            return array_map(
                fn (array $match): string => $relativePath . ':' . (substr_count(substr($contents, 0, $match[1]), "\n") + 1),
                $matches[0],
            );
        })
        ->values()
        ->all();

    expect($violations)->toBe([]);
})->group('architecture');
