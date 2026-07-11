<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Filament\Pages\Reports\AbstractReportPage;

final class MissingReportPageForTest extends AbstractReportPage
{
    protected static ?string $slug = 'reports/test-missing-report';

    public static function reportKey(): string
    {
        return 'test.missing_report';
    }

    /** @return class-string<BuildsReportSnapshot> */
    protected static function reportAction(): string
    {
        return PlainReportActionForTest::class;
    }
}
