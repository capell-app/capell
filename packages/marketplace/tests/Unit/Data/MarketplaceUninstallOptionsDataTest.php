<?php

declare(strict_types=1);

use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;

it('survives its own round trip for every combination of options', function (bool $deletePackage, bool $deleteData): void {
    $options = new MarketplaceUninstallOptionsData(deletePackage: $deletePackage, deleteData: $deleteData);

    $restored = MarketplaceUninstallOptionsData::fromPayload($options->toArray());

    expect($restored->deletePackage)->toBe($deletePackage)
        ->and($restored->deleteData)->toBe($deleteData);
})->with([
    'neither' => [false, false],
    'package only' => [true, false],
    'data only' => [false, true],
    'both' => [true, true],
]);

it('reads back a payload that went through a JSON column', function (): void {
    $options = new MarketplaceUninstallOptionsData(deletePackage: true, deleteData: true);

    $decoded = json_decode(json_encode($options->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $restored = MarketplaceUninstallOptionsData::fromPayload($decoded);

    expect($restored->deletePackage)->toBeTrue()
        ->and($restored->deleteData)->toBeTrue();
});

it('treats an absent payload as keeping everything', function (): void {
    $options = MarketplaceUninstallOptionsData::fromPayload(null);

    expect($options->deletePackage)->toBeFalse()
        ->and($options->deleteData)->toBeFalse();
});

it('refuses to read a truthy non-boolean as consent', function (): void {
    $options = MarketplaceUninstallOptionsData::fromPayload([
        'delete_package' => '1',
        'delete_data' => 1,
    ]);

    expect($options->deletePackage)->toBeFalse()
        ->and($options->deleteData)->toBeFalse();
});
