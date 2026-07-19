<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Core\Enums\FrontendRouteReservationType;
use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;

final readonly class ApplyFrontendRouteReservationsAction
{
    /** @param iterable<mixed> $contributors */
    public function __construct(
        private ReservedFrontendPathRegistry $paths,
        private ReservedFrontendDomainRegistry $domains,
        private iterable $contributors,
    ) {}

    public function __invoke(): void
    {
        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof FrontendRouteReservationContributor) {
                continue;
            }

            foreach ($contributor->reservations() as $reservation) {
                if (! $reservation instanceof FrontendRouteReservationData) {
                    continue;
                }

                match ($reservation->type) {
                    FrontendRouteReservationType::Domain => $this->domains->reserve($reservation->value),
                    FrontendRouteReservationType::ExactPath => $this->paths->reserveExact($reservation->value),
                    FrontendRouteReservationType::PathPrefix => $this->paths->reservePrefix($reservation->value),
                };
            }
        }
    }
}
