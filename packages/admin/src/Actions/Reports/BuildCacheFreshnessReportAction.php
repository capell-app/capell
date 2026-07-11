<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildCacheFreshnessReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.cache_freshness';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_cache_freshness');
    }
}
