<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\FirstRunStateData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class FirstRunChecklistFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    protected static string $settingsKey = '';

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected string $view = 'capell-admin::widgets.dashboard.first-run-checklist';

    protected int|string|array $columnSpan = 'full';

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && self::resolveState()->isFirstRun;
    }

    public function state(): FirstRunStateData
    {
        return self::resolveState();
    }

    private static function resolveState(): FirstRunStateData
    {
        $actor = auth()->user();

        return $actor instanceof Authenticatable
            ? resolve(AdminDashboardDataRequestCache::class)->firstRunState($actor)
            : FirstRunStateData::notFirstRun();
    }
}
