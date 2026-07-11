<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\BuildBrandProfileSeedAction;
use Capell\Core\ThemeStudio\Data\BookingEntryPointData;
use Capell\Core\ThemeStudio\Theme\BookingEntryPointRegistry;

it('registers booking entry points for booking-led themes', function (): void {
    $registry = new BookingEntryPointRegistry;
    $registry->register('default', new BookingEntryPointData(
        label: 'Book a recovery session',
        url: '/book',
        serviceKey: 'recovery',
    ));

    expect($registry->get()?->label)->toBe('Book a recovery session')
        ->and($registry->all())->toHaveKey('default');
});

it('builds reusable brand profile seed state', function (): void {
    $seed = BuildBrandProfileSeedAction::run(
        themeKey: 'equidynamics',
        brand: [
            'primaryColor' => '#0f5132',
            'headingFont' => 'playfair',
        ],
        presetKey: 'stable',
        assets: ['vendor/equidynamics-theme/theme.css'],
        layoutKey: 'booking-led',
    );

    expect($seed['theme']['key'])->toBe('equidynamics')
        ->and($seed['theme']['active_preset'])->toBe('stable')
        ->and($seed['theme']['brand_profile']['primaryColor'])->toBe('#0f5132')
        ->and($seed['theme']['assets'])->toBe(['vendor/equidynamics-theme/theme.css'])
        ->and($seed['layout']['key'])->toBe('booking-led');
});
