<?php

declare(strict_types=1);

namespace Capell\Admin\Support\DashboardReports;

use Capell\Admin\Contracts\DashboardReports\ActivityTrailQueryProvider;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

final class NullActivityTrailQueryProvider implements ActivityTrailQueryProvider
{
    public function build(): Builder
    {
        if (resolve(RuntimeSchemaState::class)->hasTable((new Activity)->getTable())) {
            /** @var Builder<Model> $query */
            $query = SiteAccess::current()->query(Activity::class);

            return $query;
        }

        /** @var Builder<Model> $query */
        $query = SiteAccess::current()->query(Activity::class)->whereRaw('1 = 0');

        return $query;
    }
}
