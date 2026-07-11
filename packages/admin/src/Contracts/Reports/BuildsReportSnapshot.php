<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Reports;

use Capell\Admin\Data\Reports\ReportSnapshotData;

interface BuildsReportSnapshot
{
    public function handle(): ReportSnapshotData;
}
