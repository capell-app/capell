<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildCacheFreshnessReportAction;

final class CacheFreshnessReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.cache_freshness';

    protected const string REPORT_ACTION = BuildCacheFreshnessReportAction::class;

    protected static ?string $slug = 'reports/cache-freshness';
}
