<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\FrontendRouteReservationData;

/** @internal */
interface FrontendRouteReservationContributor
{
    public const string TAG = 'capell.frontend-route-reservation-contributor';

    /** @return iterable<FrontendRouteReservationData> */
    public function reservations(): iterable;
}
