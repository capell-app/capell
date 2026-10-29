<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Core\Models\Site;
use DateTimeZone;

final class ResolveDashboardSiteTimezoneAction
{
    public function handle(Site $site): string
    {
        $zones = config('capell-admin.dashboard.work_first.site_timezones', []);
        $zone = is_array($zones) ? ($zones[$site->uuid] ?? config('app.timezone', 'UTC')) : 'UTC';

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }
}
