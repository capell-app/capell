<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

/** @experimental */
enum MetricScopeType: string
{
    case Global = 'global';
    case Site = 'site';
    case SiteLanguage = 'site_language';
}
