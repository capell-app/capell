<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\EnrichExtensionTableRecordsAction;
use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Capell\Admin\Tests\Fixtures\Extensions\FakeExtensionCatalogueMetadataProvider;

it('adds conservative catalogue metadata when no provider is installed', function (): void {
    $records = EnrichExtensionTableRecordsAction::run([
        [
            'packageName' => 'vendor/community-suite',
            'label' => 'Community Suite',
        ],
    ]);

    expect($records)->toBe([[
        'packageName' => 'vendor/community-suite',
        'label' => 'Community Suite',
        'catalogueRole' => 'extension',
        'maturity' => 'labs',
        'maturityLabel' => 'Labs',
        'includedWithCapellAll' => false,
    ]]);
});

it('enriches installed records through a catalogue metadata provider', function (): void {
    $provider = new FakeExtensionCatalogueMetadataProvider([
        'capell-app/release-suite' => new ExtensionCatalogueMetadataData(
            catalogueRole: 'extension',
            maturity: 'stable',
            maturityLabel: 'Released',
            includedWithCapellAll: true,
        ),
    ]);
    app()->instance(FakeExtensionCatalogueMetadataProvider::class, $provider);
    app()->tag(FakeExtensionCatalogueMetadataProvider::class, ExtensionCatalogueMetadataProvider::TAG);

    $records = EnrichExtensionTableRecordsAction::run([
        [
            'packageName' => 'capell-app/release-suite',
            'label' => 'Release Suite',
        ],
    ]);

    expect($records[0])->toMatchArray([
        'catalogueRole' => 'extension',
        'maturity' => 'stable',
        'maturityLabel' => 'Released',
        'includedWithCapellAll' => true,
    ]);
});

it('fails closed when a catalogue metadata provider is unavailable or inconsistent', function (FakeExtensionCatalogueMetadataProvider $provider): void {
    app()->instance(FakeExtensionCatalogueMetadataProvider::class, $provider);
    app()->tag(FakeExtensionCatalogueMetadataProvider::class, ExtensionCatalogueMetadataProvider::TAG);

    $records = EnrichExtensionTableRecordsAction::run([
        [
            'packageName' => 'vendor/community-suite',
            'label' => 'Community Suite',
        ],
    ]);

    expect($records[0])->toMatchArray([
        'catalogueRole' => 'extension',
        'maturity' => 'labs',
        'maturityLabel' => 'Labs',
        'includedWithCapellAll' => false,
    ]);
})->with([
    'provider unavailable' => fn (): FakeExtensionCatalogueMetadataProvider => new FakeExtensionCatalogueMetadataProvider(unavailable: true),
    'inconsistent metadata' => fn (): FakeExtensionCatalogueMetadataProvider => new FakeExtensionCatalogueMetadataProvider([
        'vendor/community-suite' => new ExtensionCatalogueMetadataData(
            catalogueRole: 'community',
            maturity: 'preview',
            maturityLabel: 'Released',
            includedWithCapellAll: true,
        ),
    ]),
    'Labs extension marked as included with Capell All' => fn (): FakeExtensionCatalogueMetadataProvider => new FakeExtensionCatalogueMetadataProvider([
        'vendor/community-suite' => new ExtensionCatalogueMetadataData(
            catalogueRole: 'extension',
            maturity: 'labs',
            maturityLabel: 'Labs',
            includedWithCapellAll: true,
        ),
    ]),
]);

it('fails closed when a tagged catalogue metadata provider cannot be resolved', function (): void {
    app()->bind(
        FakeExtensionCatalogueMetadataProvider::class,
        static fn (): FakeExtensionCatalogueMetadataProvider => throw new RuntimeException('Catalogue provider could not be resolved.'),
    );
    app()->tag(FakeExtensionCatalogueMetadataProvider::class, ExtensionCatalogueMetadataProvider::TAG);

    $records = EnrichExtensionTableRecordsAction::run([
        [
            'packageName' => 'vendor/community-suite',
            'label' => 'Community Suite',
        ],
    ]);

    expect($records[0])->toMatchArray([
        'catalogueRole' => 'extension',
        'maturity' => 'labs',
        'maturityLabel' => 'Labs',
        'includedWithCapellAll' => false,
    ]);
});
