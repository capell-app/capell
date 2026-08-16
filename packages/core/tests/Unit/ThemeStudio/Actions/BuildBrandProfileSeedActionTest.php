<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\BuildBrandProfileSeedAction;
use Capell\Core\ThemeStudio\Data\BrandProfileData;

it('builds a theme and layout seed from a profile array', function (): void {
    $seed = BuildBrandProfileSeedAction::run(
        themeKey: 'foundation',
        brand: ['primaryColor' => '#123456'],
        presetKey: 'editorial',
        assets: ['logo', 'hero'],
        layoutKey: 'home',
    );

    expect($seed['theme']['key'])->toBe('foundation')
        ->and($seed['theme']['active_preset'])->toBe('editorial')
        ->and($seed['theme']['brand_profile']['primaryColor'])->toBe('#123456')
        ->and($seed['theme']['assets'])->toBe(['logo', 'hero'])
        ->and($seed['layout']['key'])->toBe('home');
});

it('preserves a typed brand profile and empty optional seed values', function (): void {
    $profile = new BrandProfileData(accentColor: '#abcdef');

    $seed = BuildBrandProfileSeedAction::run('foundation', $profile);

    expect($seed['theme']['brand_profile']['accentColor'])->toBe('#abcdef')
        ->and($seed['theme']['active_preset'])->toBeNull()
        ->and($seed['theme']['assets'])->toBe([])
        ->and($seed['layout']['key'])->toBeNull();
});
