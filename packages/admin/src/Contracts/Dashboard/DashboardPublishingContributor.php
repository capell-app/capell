<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardPublicationItemData;
use Illuminate\Contracts\Auth\Authenticatable;

interface DashboardPublishingContributor
{
    public const string TAG = 'capell.admin.dashboard-publishing';

    /**
     * Return permitted upcoming publications within the next seven days.
     *
     * @return list<DashboardPublicationItemData>
     */
    public function build(Authenticatable $actor, ?int $siteId, ?string $language): array;
}
