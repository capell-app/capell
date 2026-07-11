<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildContentIntegrityReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.content_integrity';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_content_integrity');
    }
}
