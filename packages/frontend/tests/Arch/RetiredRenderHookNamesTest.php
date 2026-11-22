<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * The render hook contract and context live in Core. Frontend once kept class
 * aliases for its old names; a reappearing Frontend name would bring back a
 * second spelling for a type that reflection reports only under its Core name.
 */
it('uses only the Core names for the render hook contract and context', function (): void {
    $retiredNames = [
        'Capell\\Frontend\\Contracts\\RenderHookExtensionInterface',
        'Capell\\Frontend\\Data\\RenderHookContext',
    ];

    $root = dirname(__DIR__, 4);
    $files = (new Finder)->files()
        ->in([$root . '/packages', $root . '/tests', $root . '/docs', $root . '/workbench'])
        ->exclude(['vendor', 'node_modules'])
        ->name(['*.php', '*.md', 'composer.json'])
        ->notPath('frontend/tests/Arch/RetiredRenderHookNamesTest.php')
        ->append([$root . '/composer.json']);

    $offenders = [];

    foreach ($files as $file) {
        $path = $file instanceof SplFileInfo ? $file->getRealPath() : (string) $file;
        $contents = (string) file_get_contents($path);

        foreach ($retiredNames as $retiredName) {
            $spellings = [preg_quote($retiredName, '/'), preg_quote(str_replace('\\', '\\\\', $retiredName), '/')];

            if (preg_match('/(?:' . implode('|', $spellings) . ')(?![A-Za-z0-9_])/', $contents) === 1) {
                $offenders[] = str_replace($root . '/', '', $path) . ' uses ' . $retiredName;
            }
        }
    }

    $shimFiles = array_values(array_filter([
        'packages/frontend/src/Contracts/RenderHookExtensionInterface.php',
        'packages/frontend/src/Data/RenderHookContext.php',
    ], fn (string $shimFile): bool => file_exists($root . '/' . $shimFile)));

    expect($offenders)->toBe([])
        ->and($shimFiles)->toBe([]);
});
