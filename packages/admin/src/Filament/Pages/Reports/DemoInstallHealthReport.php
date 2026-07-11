<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;

final class DemoInstallHealthReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.demo_install_health';

    protected const string REPORT_ACTION = BuildDemoInstallHealthReportAction::class;

    protected static ?string $slug = 'reports/demo-install-health';
}
