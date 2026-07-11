<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Diagnostics;

use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;

interface SiteHealthReportExtender
{
    public const string TAG = 'capell.admin.site_health_report_extender';

    /**
     * @return list<DiagnosticSectionData>
     */
    public function sections(): array;
}
