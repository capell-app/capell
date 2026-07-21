#!/usr/bin/env php
<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\BuildExtensionSurfaceCatalogAction;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$entries = (new BuildExtensionSurfaceCatalogAction)->handle();
$jsonPath = $root . '/docs/packages/extension-surface-catalog.json';
$markdownPath = $root . '/docs/packages/extension-surface-catalog.md';
$json = json_encode([
    'schemaVersion' => 1,
    'generatedFrom' => BuildExtensionSurfaceCatalogAction::class,
    'surfaces' => array_map(static fn (ExtensionSurfaceCatalogEntryData $entry): array => [
        'id' => $entry->id,
        'kind' => $entry->kind,
        'identifier' => $entry->identifier,
        'ownerPackage' => $entry->ownerPackage,
        'stability' => $entry->stability->value,
        'introducedVersion' => $entry->introducedVersion,
        'summary' => $entry->summary,
        'contractTestId' => $entry->contractTestId,
    ], $entries),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

$table = [['Stable ID', 'Kind', 'Identifier', 'Owner', 'Stability', 'Summary']];

foreach ($entries as $entry) {
    $table[] = [
        "`{$entry->id}`",
        $entry->kind,
        '`' . str_replace('|', '\\|', $entry->identifier) . '`',
        "`{$entry->ownerPackage}`",
        $entry->stability->value,
        $entry->summary,
    ];
}

$widths = [];

foreach ($table as $row) {
    foreach ($row as $column => $value) {
        $widths[$column] = max($widths[$column] ?? 3, mb_strwidth($value));
    }
}

$formatRow = static function (array $row) use ($widths): string {
    $cells = [];

    foreach ($row as $column => $value) {
        $cells[] = $value . str_repeat(' ', $widths[$column] - mb_strwidth($value));
    }

    return '| ' . implode(' | ', $cells) . ' |';
};
$rows = array_map($formatRow, $table);
array_splice($rows, 1, 0, [$formatRow(array_map(static fn (int $width): string => str_repeat('-', $width), $widths))]);
$markdown = implode(PHP_EOL, [
    '# Extension surface catalogue',
    '',
    'Generated from executable metadata by `scripts/build-extension-surface-catalog.php`. JSON is the machine-readable source; this page is the human index.',
    '',
    ...$rows,
    '',
    'Stable entries have a direct contract test ID in the JSON catalogue. Experimental entries may change before the first public release. Internal entries are not extension APIs.',
    '',
    'Compatibility enforcement is active in `stable-extension-api-baseline.json`; drift requires an explicit compatibility decision and is never silently reformatted away.',
    '',
]);

if (in_array('--check', $argv, true)) {
    $mismatches = [];

    foreach ([$jsonPath => $json, $markdownPath => $markdown] as $path => $expected) {
        if (! is_file($path) || file_get_contents($path) !== $expected) {
            $mismatches[] = str_replace($root . '/', '', $path);
        }
    }

    if ($mismatches !== []) {
        throw new RuntimeException('Extension surface catalogue is stale: ' . implode(', ', $mismatches));
    }

    fwrite(STDOUT, 'Extension surface catalogue is current.' . PHP_EOL);

    return;
}

file_put_contents($jsonPath, $json);
file_put_contents($markdownPath, $markdown);
fwrite(STDOUT, 'Extension surface catalogue generated.' . PHP_EOL);
