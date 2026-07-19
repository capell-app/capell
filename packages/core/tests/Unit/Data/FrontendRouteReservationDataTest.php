<?php

declare(strict_types=1);

use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Core\Enums\FrontendRouteReservationType;

it('creates typed frontend route reservations', function (): void {
    expect(FrontendRouteReservationData::domain('admin.example.com'))
        ->type->toBe(FrontendRouteReservationType::Domain)
        ->value->toBe('admin.example.com')
        ->and(FrontendRouteReservationData::exactPath('sign-in'))
        ->type->toBe(FrontendRouteReservationType::ExactPath)
        ->value->toBe('sign-in')
        ->and(FrontendRouteReservationData::pathPrefix('admin'))
        ->type->toBe(FrontendRouteReservationType::PathPrefix)
        ->value->toBe('admin');
});
