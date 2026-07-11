<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildNavigationCoverageReportAction;

final class NavigationCoverageReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.navigation_coverage';

    protected const string REPORT_ACTION = BuildNavigationCoverageReportAction::class;

    protected static ?string $slug = 'reports/navigation-coverage';
}
