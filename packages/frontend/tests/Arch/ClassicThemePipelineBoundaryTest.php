<?php

declare(strict_types=1);
it('does not restore frontend classic theme adapters', function (string $class): void {
    expect(class_exists($class) || interface_exists($class))->toBeFalse();
})->with([
    'Capell\\Frontend\\Contracts\\ThemeSectionPayloadContributor',
    'Capell\\Frontend\\Support\\Themes\\DefaultTheme',
    'Capell\\Frontend\\ThemeStudio\\Adapters\\CapellFrontendThemePageAdapter',
]);
