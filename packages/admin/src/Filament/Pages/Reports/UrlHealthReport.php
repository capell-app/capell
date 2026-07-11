<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildUrlHealthReportAction;

final class UrlHealthReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.url_health';

    protected const string REPORT_ACTION = BuildUrlHealthReportAction::class;

    protected static ?string $slug = 'reports/url-health';
}
