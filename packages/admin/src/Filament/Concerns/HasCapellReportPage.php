<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Settings\AdminSettings;
use Illuminate\Support\Collection;
use Override;
use Throwable;
use UnitEnum;

trait HasCapellReportPage
{
    #[Override]
    public static function getReportDefinition(): ?ReportDefinitionData
    {
        return CapellAdmin::getReport(static::reportKey());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return static::getReportDefinition()?->resolvedLabel()
            ?? str(static::reportKey())->afterLast('.')->replace('_', ' ')->headline()->toString();
    }

    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return (string) __('capell-admin::navigation.group_reports');
    }

    #[Override]
    public static function getNavigationSort(): ?int
    {
        return static::getReportDefinition()?->navigationSort;
    }

    public static function isHiddenFromNavigationForCurrentUserRole(): bool
    {
        $report = static::getReportDefinition();

        if (! $report instanceof ReportDefinitionData) {
            return true;
        }

        try {
            $settings = resolve(AdminSettings::class);
        } catch (Throwable) {
            return ! $report->defaultEnabled;
        }

        $roleNames = self::currentUserRoleNames();

        if ($roleNames === []) {
            return ! $settings->isReportEnabledForRole('', $report);
        }

        return array_all($roleNames, fn (string $roleName): bool => ! $settings->isReportEnabledForRole($roleName, $report));
    }

    protected static function shouldRegisterCapellReportNavigation(): bool
    {
        $report = static::getReportDefinition();

        if (! $report instanceof ReportDefinitionData) {
            return false;
        }

        if (! $report->defaultEnabled) {
            return false;
        }

        return ! static::isHiddenFromNavigationForCurrentUserRole();
    }

    /** @return list<string> */
    private static function currentUserRoleNames(): array
    {
        $user = auth()->user();

        if (! is_object($user) || ! method_exists($user, 'getRoleNames')) {
            return [];
        }

        $roleNames = $user->getRoleNames();

        if ($roleNames instanceof Collection) {
            return array_values($roleNames
                ->filter(fn (mixed $roleName): bool => is_string($roleName) && $roleName !== '')
                ->values()
                ->all());
        }

        return [];
    }
}
