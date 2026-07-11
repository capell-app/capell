<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildBlueprintSchemaDriftReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.blueprint_schema_drift';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_blueprint_schema_drift');
    }
}
