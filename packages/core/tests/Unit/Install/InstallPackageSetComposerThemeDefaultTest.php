<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\InstallPackageSetComposer;
use Capell\Core\Support\Install\ThemePackageCandidates;

it('offers the preferred theme as the default of the theme question without forcing it', function (): void {
    $composer = resolve(InstallPackageSetComposer::class);
    $candidates = $composer->themeCandidates();

    expect($candidates)->toHaveKey(ThemePackageCandidates::NONE_KEY)
        ->and($composer->themePromptDefault($candidates, ThemePackageCandidates::NONE_KEY))->toBe(ThemePackageCandidates::NONE_KEY);
});

it('ignores a preferred theme that is not a known candidate or not given', function (): void {
    $composer = resolve(InstallPackageSetComposer::class);
    $catalogueDefault = resolve(ThemePackageCandidates::class)->defaultThemeKeyForCatalogue();

    expect($composer->themePromptDefault($composer->themeCandidates(), 'no-such-theme'))->toBe($catalogueDefault)
        ->and($composer->themePromptDefault($composer->themeCandidates(), null))->toBe($catalogueDefault);
});
