<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildSiteLanguageCoverageReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.site_language_coverage';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_site_language_coverage');
    }
}
