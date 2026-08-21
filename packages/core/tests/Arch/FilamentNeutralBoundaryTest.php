<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

$repositoryRoot = dirname(__DIR__, 4);

it(
    'keeps Core Filament imports inside the documented 1.x compatibility boundary',
    function () use ($repositoryRoot): void {
        $allowed = [
            'src/Actions/Diagnostics/CheckAdminPanelAccessAction.php',
            'src/Contracts/Media/MediaFieldFactory.php',
            'src/Data/PageTypeData.php',
            'src/Enums/AssetEnum.php',
            'src/Enums/CacheTime.php',
            'src/Enums/ExtensionAutoUpdatePolicyEnum.php',
            'src/Enums/ExtensionReleaseKindEnum.php',
            'src/Enums/HeaderPositionEnum.php',
            'src/Enums/ImageSourceType.php',
            'src/Enums/InteractionTargetType.php',
            'src/Enums/LayoutEnum.php',
            'src/Enums/MediaAlignment.php',
            'src/Enums/MenuAlignmentEnum.php',
            'src/Enums/PublishStatusEnum.php',
            'src/Enums/PublishVisibilityStateEnum.php',
            'src/Enums/RedirectStatusCodeEnum.php',
            'src/Enums/UrlTypeEnum.php',
            'src/Support/Media/SpatieMediaFieldFactory.php',
            'src/Support/Patching/PatchStatus.php',
        ];

        $actual = collect(File::allFiles($repositoryRoot . '/packages/core/src'))
            ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'use Filament\\'))
            ->map(fn (SplFileInfo $file): string => str_replace($repositoryRoot . '/packages/core/', '', $file->getPathname()))
            ->values()
            ->all();

        expect(array_diff($actual, $allowed))->toBeEmpty();
        expect($actual)->toHaveCount(count(array_unique($actual)));
    },
)->group('architecture', 'filament-neutral');

it('keeps neutral Core media paths free of Filament presentation types', function () use ($repositoryRoot): void {
    $paths = [
        $repositoryRoot . '/packages/core/src/Contracts/Media/MediaUploadConfigurationFactory.php',
        $repositoryRoot . '/packages/core/src/Data/Media/MediaUploadConfigurationData.php',
        $repositoryRoot . '/packages/core/src/Support/Media/SpatieMediaUploadConfigurationFactory.php',
    ];

    foreach ($paths as $path) {
        expect(File::get($path))->not->toContain('Filament\\');
    }
})->group('architecture', 'filament-neutral');

it('keeps deprecated Core media imports at the Admin compatibility edge', function () use ($repositoryRoot): void {
    $allowed = [
        'src/Providers/AdminServiceProvider.php',
        'src/Support/Media/LegacyAdminMediaFieldFactoryAdapter.php',
        'src/Support/Media/LegacyAwareAdminMediaFieldFactory.php',
    ];

    $actual = collect(File::allFiles($repositoryRoot . '/packages/admin/src'))
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'Core\\Contracts\\Media\\MediaFieldFactory'))
        ->map(fn (SplFileInfo $file): string => str_replace($repositoryRoot . '/packages/admin/', '', $file->getPathname()))
        ->values()
        ->all();

    expect(array_diff($actual, $allowed))->toBeEmpty();
})->group('architecture', 'filament-neutral');
