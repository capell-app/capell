<?php

declare(strict_types=1);

use Capell\Admin\Enums\ThemeEditorHeaderPositionEnum;
use Capell\Admin\Enums\ThemeStudioRadiusEnum;
use Illuminate\Support\Facades\Lang;

it('provides theme editor header position options in UI order', function (): void {
    expect(ThemeEditorHeaderPositionEnum::options())->toBe([
        'static' => 'Static',
        'sticky' => 'Sticky',
        'fixed' => 'Fixed',
    ]);
});

it('provides translated radius options including the visible extra large label', function (): void {
    expect(ThemeStudioRadiusEnum::options())->toBe([
        'none' => 'None',
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
        'xl' => 'Extra large',
    ]);
});

it('reads radius labels from the current translations on every call', function (): void {
    expect(ThemeStudioRadiusEnum::options()['xl'])->toBe('Extra large');

    Lang::addLines(['form.theme_studio_radius_xl' => 'Very round'], 'en', 'capell-admin');

    expect(ThemeStudioRadiusEnum::options()['xl'])->toBe('Very round');
});
