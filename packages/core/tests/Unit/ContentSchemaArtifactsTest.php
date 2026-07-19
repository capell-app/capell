<?php

declare(strict_types=1);

use Capell\Core\Support\CapellSiteSpecSchema;
use Symfony\Component\Process\Process;

it('keeps the generated SiteSpec schema artifact current', function (): void {
    $root = dirname(__DIR__, 4);
    $expected = json_encode(
        CapellSiteSpecSchema::toArray(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;

    expect(file_get_contents($root . '/docs/packages/site-spec.schema.json'))->toBe($expected);
});

it('can regenerate the content schema artifact deterministically', function (): void {
    $root = dirname(__DIR__, 4);
    $before = file_get_contents($root . '/docs/packages/site-spec.schema.json');
    $process = new Process([PHP_BINARY, $root . '/scripts/build-content-schemas.php'], $root);
    $process->mustRun();

    expect(file_get_contents($root . '/docs/packages/site-spec.schema.json'))->toBe($before);
});
