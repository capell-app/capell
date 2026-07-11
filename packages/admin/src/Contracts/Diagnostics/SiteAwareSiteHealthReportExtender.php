<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Diagnostics;

use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;

interface SiteAwareSiteHealthReportExtender extends SiteHealthReportExtender
{
    /**
     * @return list<DiagnosticSectionData>
     */
    public function sectionsForSite(?int $siteId): array;
}
