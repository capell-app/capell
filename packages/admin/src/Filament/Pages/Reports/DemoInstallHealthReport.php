<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Diagnostics\BuildOperationsCenterAction;
use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;
use Capell\Admin\Data\Diagnostics\OperationsCenterData;
use Capell\Admin\Data\Reports\ReportSnapshotData;

final class DemoInstallHealthReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.demo_install_health';

    protected const string REPORT_ACTION = BuildDemoInstallHealthReportAction::class;

    public int $reportRun = 0;

    protected static ?string $slug = 'reports/demo-install-health';

    public function rerun(): void
    {
        $this->reportRun++;
    }

    public function operationsCenter(): OperationsCenterData
    {
        return BuildOperationsCenterAction::run();
    }

    public function reportSnapshot(): ReportSnapshotData
    {
        return $this->operationsCenter()->snapshot;
    }
}
