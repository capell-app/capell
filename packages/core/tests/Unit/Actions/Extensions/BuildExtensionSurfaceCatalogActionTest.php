<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\BuildExtensionSurfaceCatalogAction;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;

it('catalogues every supported extension surface kind from explicit metadata', function (): void {
    $catalog = BuildExtensionSurfaceCatalogAction::run();

    expect(array_column($catalog, 'kind'))->toContain(
        'contract',
        'facade',
        'dto',
        'event',
        'tagged-service',
        'config',
        'render-hook',
        'testing',
        'internal',
    );

    foreach ($catalog as $entry) {
        expect($entry->id)->not->toBe('')
            ->and($entry->ownerPackage)->toStartWith('capell-app/')
            ->and($entry->stability)->toBeInstanceOf(ExtensionSurfaceStability::class)
            ->and($entry->introducedVersion)->toMatch('/^\d+\.\d+\.\d+$/')
            ->and($entry->summary)->not->toBe('');

        if ($entry->stability === ExtensionSurfaceStability::Stable) {
            expect($entry->contractTestId)->not->toBeNull();
        }
    }
});

it('rejects duplicate stable IDs', function (): void {
    $entry = new ExtensionSurfaceCatalogEntryData(
        id: 'core.contract.extension-contribution',
        kind: 'contract',
        identifier: 'Duplicate',
        ownerPackage: 'capell-app/core',
        stability: ExtensionSurfaceStability::Experimental,
        introducedVersion: '1.0.0',
        summary: 'Duplicate fixture.',
    );

    expect(fn (): array => BuildExtensionSurfaceCatalogAction::run([$entry]))
        ->toThrow(InvalidArgumentException::class, 'Duplicate extension surface ID');
});

it('rejects missing ownership metadata', function (): void {
    $entry = new ExtensionSurfaceCatalogEntryData(
        id: 'fixture.missing-owner',
        kind: 'contract',
        identifier: 'Fixture',
        ownerPackage: '',
        stability: ExtensionSurfaceStability::Experimental,
        introducedVersion: '1.0.0',
        summary: 'Fixture.',
    );

    expect(fn (): array => BuildExtensionSurfaceCatalogAction::run([$entry]))
        ->toThrow(InvalidArgumentException::class, 'require an ID, owner, and summary');
});

it('rejects stable surfaces without a direct contract test', function (): void {
    $entry = new ExtensionSurfaceCatalogEntryData(
        id: 'fixture.stable-without-test',
        kind: 'contract',
        identifier: 'Fixture',
        ownerPackage: 'capell-app/core',
        stability: ExtensionSurfaceStability::Stable,
        introducedVersion: '1.0.0',
        summary: 'Fixture.',
    );

    expect(fn (): array => BuildExtensionSurfaceCatalogAction::run([$entry]))
        ->toThrow(InvalidArgumentException::class, 'requires a contract test ID');
});
