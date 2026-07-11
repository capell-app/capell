<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;

final class PlainReportActionForTest implements BuildsReportSnapshot
{
    public function handle(): ReportSnapshotData
    {
        return new ReportSnapshotData(
            key: 'test.plain_report',
            emptyState: 'The plain report action ran.',
            metrics: [
                new ReportMetricData(
                    label: 'Open findings',
                    value: 3,
                    description: 'Outstanding report findings.',
                ),
            ],
        );
    }
}
