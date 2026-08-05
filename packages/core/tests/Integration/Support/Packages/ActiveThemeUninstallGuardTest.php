<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Packages\ActiveThemeUninstallGuard;

function guardedThemePackage(string $themeKey): PackageData
{
    $composerName = 'capell-app/theme-' . $themeKey;
    CapellCore::registerPackage($composerName, PackageTypeEnum::Theme, version: '1.0.0');
    $package = CapellCore::getPackage($composerName);
    $package->themeKey = $themeKey;
    CapellCore::markPackageInstalled($package->name);

    return $package;
}

it('reports no refusal for a package that ships no theme', function (): void {
    CapellCore::registerPackage('capell-app/plain-plugin', PackageTypeEnum::Plugin, version: '1.0.0');

    expect(new ActiveThemeUninstallGuard()->refusalReason(CapellCore::getPackage('capell-app/plain-plugin')))->toBeNull();
});

it('reports no refusal for a theme nothing uses', function (): void {
    $package = guardedThemePackage('editorial');
    Theme::factory()->createOne(['key' => 'editorial']);

    expect(new ActiveThemeUninstallGuard()->refusalReason($package))->toBeNull();
});

it('reports the refusal for a theme a site still uses, without throwing', function (): void {
    $package = guardedThemePackage('editorial');
    $theme = Theme::factory()->createOne(['key' => 'editorial']);
    Site::factory()->theme($theme)->createOne();

    expect(new ActiveThemeUninstallGuard()->refusalReason($package))
        ->toBeString()
        ->toContain('1 site(s), 0 layout(s), global active theme: no');
});

it('throws exactly the refusal it reports', function (): void {
    $package = guardedThemePackage('editorial');
    $theme = Theme::factory()->createOne(['key' => 'editorial']);
    Site::factory()->theme($theme)->createOne();

    $guard = new ActiveThemeUninstallGuard;
    $reason = $guard->refusalReason($package);

    expect($reason)->toBeString();
    expect(fn (): null => $guard->assert($package))->toThrow(Exception::class, (string) $reason);
});
