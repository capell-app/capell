<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricVisibility: string
{
    case SiteAdmin = 'site_admin';
    case PlatformOps = 'platform_ops';
}
