<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildAccessibilityReadinessReportAction;

final class AccessibilityReadinessReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.accessibility_readiness';

    protected const string REPORT_ACTION = BuildAccessibilityReadinessReportAction::class;

    protected static ?string $slug = 'reports/accessibility-readiness';
}
