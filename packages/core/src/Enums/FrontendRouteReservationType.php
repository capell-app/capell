<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum FrontendRouteReservationType: string
{
    case Domain = 'domain';
    case ExactPath = 'exact-path';
    case PathPrefix = 'path-prefix';
}
