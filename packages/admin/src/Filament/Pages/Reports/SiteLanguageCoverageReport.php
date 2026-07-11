<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildSiteLanguageCoverageReportAction;

final class SiteLanguageCoverageReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.site_language_coverage';

    protected const string REPORT_ACTION = BuildSiteLanguageCoverageReportAction::class;

    protected static ?string $slug = 'reports/site-language-coverage';
}
