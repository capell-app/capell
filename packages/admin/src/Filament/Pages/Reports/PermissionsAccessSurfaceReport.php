<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildPermissionsAccessSurfaceReportAction;

final class PermissionsAccessSurfaceReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.permissions_access_surface';

    protected const string REPORT_ACTION = BuildPermissionsAccessSurfaceReportAction::class;

    protected static ?string $slug = 'reports/permissions-access-surface';
}
