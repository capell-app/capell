<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

final class BuildPermissionsAccessSurfaceReportAction extends BuildEmptyReportAction
{
    protected function reportKey(): string
    {
        return 'core.permissions_access_surface';
    }

    protected function emptyState(): string
    {
        return __('capell-admin::reports.empty_state_permissions_access_surface');
    }
}
