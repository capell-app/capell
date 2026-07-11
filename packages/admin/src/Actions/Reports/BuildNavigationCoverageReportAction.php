<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildNavigationCoverageReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.navigation_coverage';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_navigation_coverage');
    }
}
