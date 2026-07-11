<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildPackageReadinessReportAction;

final class PackageReadinessReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.package_readiness';

    protected const string REPORT_ACTION = BuildPackageReadinessReportAction::class;

    protected static ?string $slug = 'reports/package-readiness';
}
