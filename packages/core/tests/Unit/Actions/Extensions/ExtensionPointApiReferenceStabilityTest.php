<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\BuildExtensionSurfaceCatalogAction;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;

/**
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function extensionPointReferenceRows(): array
{
    $stableShortNames = [];

    foreach (BuildExtensionSurfaceCatalogAction::run() as $entry) {
        if ($entry->stability === ExtensionSurfaceStability::Stable) {
            $stableShortNames[] = class_basename(str_replace('\\', '/', $entry->identifier));
        }
    }

    $rows = [];
    $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 6) . '/docs/packages/extension-point-api-reference.md'));
    $columns = null;

    foreach ($lines as $line) {
        if (! str_starts_with($line, '|')) {
            $columns = null;

            continue;
        }

        $cells = array_map(trim(...), explode('|', trim($line, " |\t")));

        if ($cells[0] === 'Need') {
            $columns = array_flip($cells);

            continue;
        }

        if ($columns === null) {
            continue;
        }

        if (! isset($columns['Stability'])) {
            continue;
        }

        if (str_starts_with($cells[0], '---')) {
            continue;
        }

        preg_match_all('/`([A-Za-z]+)(?:::|`|\()/', $cells[$columns['API']], $bare);
        preg_match_all('/`[^`]*?\b([A-Z][A-Za-z]+)(?:::|\()/', $cells[$columns['API']], $inline);
        $expected = array_intersect([...$bare[1], ...$inline[1]], $stableShortNames) === [] ? 'experimental' : 'stable';
        $rows[$cells[0]] = [$cells[$columns['API']], $cells[$columns['Stability']], $expected];
    }

    return $rows;
}

it('states a stability for every extension point in the API reference', function (): void {
    expect(extensionPointReferenceRows())->not->toBeEmpty();
});

it('labels each extension point with the stability its API has in the surface catalogue', function (string $api, string $stated, string $expected): void {
    expect($stated)->toBe($expected);
})->with(extensionPointReferenceRows());
