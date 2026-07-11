<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildPublishingReadinessReportAction;

final class PublishingReadinessReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.publishing_readiness';

    protected const string REPORT_ACTION = BuildPublishingReadinessReportAction::class;

    protected static ?string $slug = 'reports/publishing-readiness';
}
