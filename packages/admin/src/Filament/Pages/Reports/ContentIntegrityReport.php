<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildContentIntegrityReportAction;

final class ContentIntegrityReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.content_integrity';

    protected const string REPORT_ACTION = BuildContentIntegrityReportAction::class;

    protected static ?string $slug = 'reports/content-integrity';
}
