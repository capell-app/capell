<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;

it('exports one blueprint block schema to a file', function (): void {
    $blueprint = Blueprint::factory()->create(['key' => 'landing-page']);
    $outputPath = storage_path('framework/testing/landing-page-block-schema.json');

    try {
        artisanCommand('capell:blueprint-block-schema', [
            'blueprint' => $blueprint->key,
            '--out' => $outputPath,
        ])->assertSuccessful();

        $schema = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

        expect($schema['type'])->toBe('array')
            ->and($schema['items']['properties']['type']['enum'])->toContain('content');
    } finally {
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }
});

it('requires a blueprint key or the all option', function (): void {
    artisanCommand('capell:blueprint-block-schema')->assertExitCode(2);
});
