<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Routing;

use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Data\FrontendRouteReservationData;

final class AdminFrontendRouteReservationContributor implements FrontendRouteReservationContributor
{
    public function reservations(): iterable
    {
        $path = AdminPanelEntrypoint::path();

        if ($path !== '') {
            yield FrontendRouteReservationData::pathPrefix($path);
        }

        $domain = AdminPanelEntrypoint::domain();

        if ($domain !== null) {
            yield FrontendRouteReservationData::domain($domain);
        }
    }
}
