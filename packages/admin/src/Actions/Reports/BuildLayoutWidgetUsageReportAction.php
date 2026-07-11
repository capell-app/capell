<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildLayoutWidgetUsageReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.layout_widget_usage';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_layout_widget_usage');
    }
}
