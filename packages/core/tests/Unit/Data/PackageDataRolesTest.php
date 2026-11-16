<?php

declare(strict_types=1);

use Capell\Core\Data\Manifest\ExtensionInstallTipData;
use Capell\Core\Data\Manifest\ExtensionLegacyTableData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Manifest\CapellManifestData;

it('exposes the roles, priority, install tips and legacy tables a manifest declares', function (): void {
    $manifest = capellManifestV3Array();
    $manifest['roles'] = ['developer-tooling'];
    $manifest['rolePriority'] = 7;
    $manifest['install'] = [
        'tips' => [['variant' => 'blog', 'priority' => 3]],
        'legacyTables' => [['name' => 'tags', 'configKey' => 'tags.table', 'migration' => 'create_tags_table']],
    ];

    $package = new PackageData(
        name: 'vendor/package',
        type: PackageTypeEnum::Plugin,
        manifest: CapellManifestData::fromArray($manifest),
    );

    expect($package->roles())->toBe([PackageRole::DeveloperTooling])
        ->and($package->hasRole(PackageRole::DeveloperTooling))->toBeTrue()
        ->and($package->hasRole(PackageRole::DefaultTheme))->toBeFalse()
        ->and($package->rolePriority())->toBe(7)
        ->and($package->installTips())->toHaveCount(1)
        ->and($package->installTips()[0])->toBeInstanceOf(ExtensionInstallTipData::class)
        ->and($package->installTips()[0]->variant)->toBe('blog')
        ->and($package->legacyTables()[0])->toBeInstanceOf(ExtensionLegacyTableData::class)
        ->and($package->legacyTables()[0]->name)->toBe('tags');
});

it('reports no roles, priority zero and no install metadata without a manifest or declarations', function (PackageData $package): void {
    expect($package->roles())->toBe([])
        ->and($package->hasRole(PackageRole::DefaultTheme))->toBeFalse()
        ->and($package->rolePriority())->toBe(0)
        ->and($package->installTips())->toBe([])
        ->and($package->legacyTables())->toBe([]);
})->with([
    'no manifest' => fn (): PackageData => new PackageData(name: 'vendor/plain', type: PackageTypeEnum::Plugin),
    'a manifest declaring none' => fn (): PackageData => new PackageData(
        name: 'vendor/package',
        type: PackageTypeEnum::Plugin,
        manifest: CapellManifestData::fromArray(capellManifestV3Array()),
    ),
]);
