<?php

declare(strict_types=1);

it('does not declare case-only duplicate package test paths', function (): void {
    $configuration = (string) file_get_contents(dirname(__DIR__) . '/Pest.php');

    preg_match_all("/'(\.\.\/[^']+\/tests)'/", $configuration, $matches);

    $paths = $matches[1] ?? [];
    $normalisedPaths = array_map(
        static fn (string $path): string => strtolower(str_replace('\\', '/', $path)),
        $paths,
    );

    expect($paths)->not->toBeEmpty()
        ->and($normalisedPaths)->toHaveCount(count(array_unique($normalisedPaths)));
});
