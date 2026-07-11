<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildLayoutWidgetUsageReportAction;

final class LayoutWidgetUsageReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.layout_widget_usage';

    protected const string REPORT_ACTION = BuildLayoutWidgetUsageReportAction::class;

    protected static ?string $slug = 'reports/layout-widget-usage';
}
