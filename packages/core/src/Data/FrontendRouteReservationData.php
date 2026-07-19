<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\FrontendRouteReservationType;

final readonly class FrontendRouteReservationData
{
    public function __construct(
        public FrontendRouteReservationType $type,
        public string $value,
    ) {}

    public static function domain(string $domain): self
    {
        return new self(FrontendRouteReservationType::Domain, $domain);
    }

    public static function exactPath(string $path): self
    {
        return new self(FrontendRouteReservationType::ExactPath, $path);
    }

    public static function pathPrefix(string $prefix): self
    {
        return new self(FrontendRouteReservationType::PathPrefix, $prefix);
    }
}
