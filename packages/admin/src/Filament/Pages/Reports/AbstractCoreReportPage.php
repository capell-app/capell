<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildEmptyReportAction;
use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;

abstract class AbstractCoreReportPage extends AbstractReportPage
{
    public const string REPORT_KEY = '';

    /** @var class-string<BuildsReportSnapshot> */
    protected const string REPORT_ACTION = BuildEmptyReportAction::class;

    public static function reportKey(): string
    {
        return static::REPORT_KEY;
    }

    /** @return class-string<BuildsReportSnapshot> */
    protected static function reportAction(): string
    {
        return static::REPORT_ACTION;
    }
}
