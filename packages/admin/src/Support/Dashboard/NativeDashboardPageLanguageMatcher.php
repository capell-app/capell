<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Actions\Dashboard\ConstrainDashboardPageLanguageAction;
use Capell\Admin\Contracts\Dashboard\DashboardPageLanguageMatcher;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class NativeDashboardPageLanguageMatcher implements DashboardPageLanguageMatcher
{
    #[Override]
    public function matches(Authenticatable $actor, Page $page, string $language): bool
    {
        $access = SiteAccess::forActor($actor, acrossAssignedSites: true);
        $pages = $access->scope(Page::query()->withoutGlobalScopes())
            ->whereNull('deleted_at')->whereKey($page->getKey())->where('site_id', $page->site_id);

        return resolve(ConstrainDashboardPageLanguageAction::class)->handle($pages, $access, $language)->exists();
    }
}
