<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildPublicRenderSafetyReportAction;

final class PublicRenderSafetyReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.public_render_safety';

    protected const string REPORT_ACTION = BuildPublicRenderSafetyReportAction::class;

    protected static ?string $slug = 'reports/public-render-safety';
}
