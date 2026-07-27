<?php

declare(strict_types=1);

use Capell\Core\Contracts\SettingsSchemaContract as CoreSettingsSchemaContract;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface as CoreThemePreviewRendererInterface;

it('keeps deprecated admin contract aliases compatible with their core contracts', function (): void {
    $settingsContract = implode('\\', ['Capell', 'Admin', 'Contracts', 'SettingsSchemaContract']);
    $themePreviewContract = implode('\\', ['Capell', 'Admin', 'Contracts', 'Themes', 'ThemePreviewRendererInterface']);

    expect(class_implements($settingsContract))->toContain(CoreSettingsSchemaContract::class)
        ->and(class_implements($themePreviewContract))->toContain(CoreThemePreviewRendererInterface::class);
});
