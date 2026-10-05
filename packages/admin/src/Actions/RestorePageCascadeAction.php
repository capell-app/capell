<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Authorise the entire recorded restore cascade under lock before restoring any page. */
final class RestorePageCascadeAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): bool
    {
        // Re-read all candidates on each attempt: overlapping ancestor locks can deadlock.
        return $page->getConnection()->transaction(function () use ($page): bool {
            $locked = SiteAccess::current()->query($page::class)->onlyTrashed()->whereKey($page->getKey())->lockForUpdate()->first();
            if (! $locked instanceof Page) {
                return false;
            }

            Gate::authorize('restore', $locked);
            if (! CanRestorePageCascadeAction::run($locked, lockForUpdate: true)) {
                return false;
            }

            return $locked->restore();
        }, attempts: 3);
    }
}
