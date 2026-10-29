<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns;

use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Facades\Gate;

trait ChecksWorkFirstWidgetAccess
{
    public static function widgetEnabled(string $key): bool
    {
        $switches = config('capell-admin.dashboard.work_first.widgets', []);
        $actor = auth()->user();

        return config('capell-admin.dashboard.work_first.enabled', false) === true
            && (! is_array($switches) || (($switches[$key] ?? true) !== false && ($switches[static::class] ?? true) !== false))
            && $actor !== null && ((SiteAccess::forActor($actor)->isGlobal() && Gate::forUser($actor)->allows('viewAny', Page::class))
                || collect(resolve(DashboardSiteEvaluator::class)->siteIds($actor))
                    ->contains(fn (int $siteId): bool => resolve(DashboardSiteEvaluator::class)->allows($actor, $siteId, fn (): bool => Gate::forUser($actor)->allows('viewAny', Page::class))));
    }

    protected function rejectInvalidWorkFirstScope(): bool
    {
        return true;
    }
}
