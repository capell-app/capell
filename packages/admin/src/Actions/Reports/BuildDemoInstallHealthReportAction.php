<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildDemoInstallHealthReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.demo_install_health';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_demo_install_health');
    }
}
