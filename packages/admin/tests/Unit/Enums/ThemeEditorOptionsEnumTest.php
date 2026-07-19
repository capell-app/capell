<?php

declare(strict_types=1);

use Capell\Admin\Enums\ThemeEditorHeaderPositionEnum;
use Capell\Admin\Enums\ThemeStudioCardDensityEnum;
use Capell\Admin\Enums\ThemeStudioRadiusEnum;

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

it('provides every theme studio card density option', function (): void {
    expect(array_map(
        fn (ThemeStudioCardDensityEnum $density): array => [$density->value, $density->getLabel()],
        ThemeStudioCardDensityEnum::cases(),
    ))->toBe([
        ['compact', 'Compact'],
        ['comfortable', 'Comfortable'],
        ['spacious', 'Spacious'],
    ]);
});
