<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallProfileRepository;

it('loads install profiles from config', function (): void {
    config([
        'capell.install_profiles' => [
            'equidynamics' => [
                'packages' => 'capell-app/bookings, capell-app/seo-suite',
                'theme' => 'equidynamics',
                'demo' => true,
                'languages' => ['en'],
                'sites' => ['Equidynamics'],
            ],
        ],
    ]);

    $profile = resolve(InstallProfileRepository::class)->find('equidynamics');

    expect($profile?->packages)->toBe(['capell-app/bookings', 'capell-app/seo-suite'])
        ->and($profile?->theme)->toBe('equidynamics')
        ->and($profile?->demo)->toBeTrue()
        ->and($profile?->languages)->toBe(['en'])
        ->and($profile?->sites)->toBe(['Equidynamics']);
});
