<?php

declare(strict_types=1);

use Capell\Admin\Contracts\SettingsSchemaContract as DeprecatedSettingsSchemaContract;
use Capell\Admin\Contracts\Themes\ThemePreviewRendererInterface as DeprecatedThemePreviewRendererInterface;
use Capell\Core\Contracts\SettingsSchemaContract as CoreSettingsSchemaContract;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface as CoreThemePreviewRendererInterface;

it('keeps deprecated admin contract aliases compatible with their core contracts', function (): void {
    expect(is_a(DeprecatedSettingsSchemaContract::class, CoreSettingsSchemaContract::class, true))->toBeTrue()
        ->and(is_a(DeprecatedThemePreviewRendererInterface::class, CoreThemePreviewRendererInterface::class, true))->toBeTrue();
});
