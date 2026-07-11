<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Actions\Reports\BuildContentIntegrityReportAction;
use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Filament\Pages\Reports\AbstractReportPage;

final class CustomReportPageForRegistry extends AbstractReportPage
{
    protected static ?string $slug = 'reports/test-custom-report';

    public static function reportKey(): string
    {
        return 'test.custom_report';
    }

    /** @return class-string<BuildsReportSnapshot> */
    protected static function reportAction(): string
    {
        return BuildContentIntegrityReportAction::class;
    }
}
