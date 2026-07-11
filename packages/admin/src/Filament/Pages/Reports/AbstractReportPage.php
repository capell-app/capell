<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use BackedEnum;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Contracts\Reports\CapellReportPage;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Filament\Concerns\HasCapellReportPage;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Override;

abstract class AbstractReportPage extends Page implements CapellReportPage
{
    use HasCapellReportPage;
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|BackedEnum|null $activeNavigationIcon = null;

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'capell-admin::filament.pages.reports.report';

    /** @var array<class-string, string|null> */
    private static array $pagePermissionKeysByClass = [];

    /** @return class-string<BuildsReportSnapshot> */
    abstract protected static function reportAction(): string;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return static::shouldRegisterCapellReportNavigation() && static::canAccess();
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return static::getNavigationLabel();
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return static::getReportDefinition()?->resolvedDescription();
    }

    public function reportSnapshot(): ReportSnapshotData
    {
        $actionClass = static::reportAction();

        return resolve($actionClass)->handle();
    }

    protected static function getPagePermission(): ?string
    {
        if (! array_key_exists(static::class, self::$pagePermissionKeysByClass)) {
            $page = FilamentShield::getPages()[static::class] ?? null;

            self::$pagePermissionKeysByClass[static::class] = $page ? array_key_first($page['permissions']) : null;
        }

        return self::$pagePermissionKeysByClass[static::class];
    }
}
