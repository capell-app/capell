<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Reports;

use Capell\Admin\Data\Reports\ReportDefinitionData;

interface CapellReportPage
{
    public static function reportKey(): string;

    public static function getReportDefinition(): ?ReportDefinitionData;
}
