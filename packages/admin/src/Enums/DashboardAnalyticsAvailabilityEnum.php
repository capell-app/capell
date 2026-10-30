<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

/**
 * Whether a dashboard analytics snapshot reflects real observations. A snapshot
 * of zeros is only evidence of "no activity" when the provider actually ran.
 */
enum DashboardAnalyticsAvailabilityEnum: string
{
    /** The provider ran, so zero values mean no activity was observed. */
    case Available = 'available';

    /** The provider failed, so the figures say nothing about real activity. */
    case Unavailable = 'unavailable';

    /** No analytics provider is configured, so there are no figures to show. */
    case NotConfigured = 'not_configured';
}
