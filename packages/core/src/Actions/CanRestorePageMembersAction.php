<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Support\PageRestoreReadOnlyScope;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CanRestorePageMembersAction
{
    use AsFake;
    use AsObject;

    /** @param Collection<int, Page> $members */
    public function handle(Collection $members): bool
    {
        $page = $members->first();
        if (! $page instanceof Page) {
            return true;
        }

        // Unauthenticated console maintenance has no acting user. Web requests never inherit it.
        if (auth()->user() === null) {
            return app()->runningInConsole();
        }

        return PageRestoreReadOnlyScope::run($page->getConnection(), static function () use ($members): bool {
            $actorId = auth()->id();
            $access = SiteAccess::current();
            $members->loadMissing(['blueprint.roleRestrictions', 'site']);
            foreach ($members as $member) {
                if (! $access->canUseRecord($member) || Gate::denies('restore', $member)) {
                    return false;
                }
            }

            return $actorId === auth()->id() && $access->allowedSiteIds() === SiteAccess::current()->allowedSiteIds();
        });
    }
}
