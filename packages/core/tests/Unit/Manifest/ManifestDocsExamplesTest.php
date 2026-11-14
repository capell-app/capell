<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\ManifestValidator;

/**
 * Every complete manifest an author is shown must pass the same validator the loader,
 * `capell:extension-audit` and Marketplace submission use.
 *
 * @return array<string, array{0: string}>
 */
function manifestDocsExamples(): array
{
    $root = dirname(__DIR__, 5);
    $files = [
        ...glob($root . '/docs/{,*/,*/*/}*.md', GLOB_BRACE) ?: [],
        ...glob($root . '/packages/*/{docs/,docs/*/,}*.md', GLOB_BRACE) ?: [],
    ];
    $examples = [];

    foreach (array_unique($files) as $file) {
        $contents = (string) file_get_contents($file);

        if (! str_contains($contents, '"manifest-version"')) {
            continue;
        }

        preg_match_all('/```json[^\n]*\n(.*?)```/s', $contents, $matches);

        foreach ($matches[1] as $index => $block) {
            if (str_contains($block, '"manifest-version"')) {
                $examples[substr($file, strlen($root) + 1) . ' #' . ($index + 1)] = [$block];
            }
        }
    }

    return $examples;
}

it('finds the manifest examples in the author documentation', function (): void {
    expect(manifestDocsExamples())->not->toBeEmpty();
});

it('validates every documented manifest example with the shared validator', function (string $block): void {
    $manifest = json_decode($block, true, flags: JSON_THROW_ON_ERROR);

    $problems = (new ManifestValidator)->structureProblems($manifest);

    expect($problems)->toBe([]);
})->with(manifestDocsExamples());
