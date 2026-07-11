<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\DashboardReports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ActivityTrailQueryProvider
{
    /**
     * @return Builder<Model>
     */
    public function build(): Builder;
}
