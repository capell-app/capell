<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use LogicException;

abstract class AbstractCoreReportPage extends AbstractReportPage
{
    public const string REPORT_KEY = '';

    /**
     * Concrete report pages must override this with their own builder action.
     *
     * @var class-string<BuildsReportSnapshot>|''
     */
    protected const string REPORT_ACTION = '';

    public static function reportKey(): string
    {
        return static::REPORT_KEY;
    }

    /** @return class-string<BuildsReportSnapshot> */
    protected static function reportAction(): string
    {
        $reportActionClass = static::REPORT_ACTION;

        if (! is_a($reportActionClass, BuildsReportSnapshot::class, true)) {
            throw new LogicException(sprintf(
                '%s must define REPORT_ACTION as a %s implementation.',
                static::class,
                BuildsReportSnapshot::class,
            ));
        }

        return $reportActionClass;
    }
}
