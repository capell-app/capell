<?php

declare(strict_types=1);
it('does not restore the classic theme section rendering pipeline', function (string $class): void {
    expect(class_exists($class) || interface_exists($class))->toBeFalse();
})->with([
    'Capell\\Core\\ThemeStudio\\Actions\\RenderCurrentThemePageAction',
    'Capell\\Core\\ThemeStudio\\Actions\\RenderThemePageAction',
    'Capell\\Core\\ThemeStudio\\Contracts\\SectionRenderer',
    'Capell\\Core\\ThemeStudio\\Contracts\\ThemePageAdapter',
    'Capell\\Core\\ThemeStudio\\Contracts\\ThemeRenderer',
    'Capell\\Core\\ThemeStudio\\Contracts\\ThemeSection',
    'Capell\\Core\\ThemeStudio\\Data\\ThemePageData',
    'Capell\\Core\\ThemeStudio\\Rendering\\BladeThemeRenderer',
    'Capell\\Core\\ThemeStudio\\Rendering\\ViewSectionRenderer',
    'Capell\\Core\\ThemeStudio\\Theme\\ThemePageAdapterRegistry',
    'Capell\\Core\\ThemeStudio\\Theme\\ThemePackageRegistrar',
]);
