<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Enums\DashboardAnalyticsAvailabilityEnum;
use Illuminate\Contracts\Auth\Authenticatable;

/** An analytics source with nothing to report, in a chosen state; records the period it was asked for. */
final class EmptyAnalyticsDataProvider implements DashboardAnalyticsDataProvider
{
    /** @var list<string> */
    public static array $periods = [];

    public function __construct(private readonly DashboardAnalyticsAvailabilityEnum $availability) {}

    public function build(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        self::$periods[] = $period;

        return new DashboardAnalyticsSnapshotData(totalViews: 0, recentViews: 0, searches: 0, activePages: 0, availability: $this->availability);
    }
}
