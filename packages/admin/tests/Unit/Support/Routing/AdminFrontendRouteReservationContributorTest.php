<?php

declare(strict_types=1);

use Capell\Admin\Support\Routing\AdminFrontendRouteReservationContributor;
use Capell\Core\Enums\FrontendRouteReservationType;

it('contributes the configured admin path and domain', function (): void {
    config([
        'capell-admin.path' => '/control/',
        'capell-admin.domain' => ' admin.example.com ',
    ]);

    $reservations = collect((new AdminFrontendRouteReservationContributor)->reservations());

    expect($reservations->pluck('value')->all())->toBe(['control', 'admin.example.com'])
        ->and($reservations->pluck('type')->all())->toBe([
            FrontendRouteReservationType::PathPrefix,
            FrontendRouteReservationType::Domain,
        ]);
});

it('contributes only the domain when admin is mounted at the domain root', function (): void {
    config([
        'capell-admin.path' => '/',
        'capell-admin.domain' => 'admin.example.com',
    ]);

    $reservations = collect((new AdminFrontendRouteReservationContributor)->reservations());

    expect($reservations->map(
        static fn ($reservation): array => [$reservation->type, $reservation->value],
    )->all())->toBe([
        [FrontendRouteReservationType::Domain, 'admin.example.com'],
    ]);
});

it('falls back to the default admin path when domain and path are blank', function (): void {
    config([
        'capell-admin.path' => '',
        'capell-admin.domain' => ' ',
    ]);

    $reservations = collect((new AdminFrontendRouteReservationContributor)->reservations());

    expect($reservations->map(
        static fn ($reservation): array => [$reservation->type, $reservation->value],
    )->all())->toBe([
        [FrontendRouteReservationType::PathPrefix, 'admin'],
    ]);
});
