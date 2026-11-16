<?php

declare(strict_types=1);

use Capell\Core\Actions\Scaffolding\ScaffoldPackageAction;
use Capell\Core\Actions\Scaffolding\ScaffoldThemePackageAction;
use Capell\Core\Data\Scaffolding\PackageScaffoldInputData;
use Capell\Core\Data\Scaffolding\ThemeScaffoldInputData;
use Capell\Core\Enums\PackageScaffoldProfile;

it('refuses the full package profile without a role package and writes nothing', function (): void {
    $target = sys_get_temp_dir() . '/capell-scaffold-guard-' . bin2hex(random_bytes(6));

    expect(fn (): mixed => ScaffoldPackageAction::run(new PackageScaffoldInputData(
        packageName: 'vendor/guarded',
        namespace: 'Vendor\\Guarded',
        slug: 'guarded',
        displayName: 'Guarded',
        tier: 'free',
        targetPath: $target,
        profile: PackageScaffoldProfile::Full,
    )))->toThrow(RuntimeException::class, 'scaffold-full-profile')
        ->and($target)->not->toBeDirectory();
});

it('refuses a theme scaffold without a base theme package and writes nothing', function (): void {
    $target = sys_get_temp_dir() . '/capell-scaffold-guard-' . bin2hex(random_bytes(6));

    expect(fn (): mixed => ScaffoldThemePackageAction::run(new ThemeScaffoldInputData(
        packageName: 'vendor/guarded-theme',
        namespace: 'Vendor\\GuardedTheme',
        slug: 'guarded-theme',
        themeKey: 'guarded',
        displayName: 'Guarded',
        targetPath: $target,
        local: false,
    )))->toThrow(RuntimeException::class, 'default-theme')
        ->and($target)->not->toBeDirectory();
});
