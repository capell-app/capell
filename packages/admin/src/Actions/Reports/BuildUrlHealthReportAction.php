<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildUrlHealthReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.url_health';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_url_health');
    }
}
