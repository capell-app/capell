<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Data\PageRestoreResultData;
use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Exceptions\PageRestoreCancelledException;
use Capell\Core\Models\Page;
use Capell\Core\Support\PageRestoreReadOnlyScope;
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
        return $this->restoreWithResult($page)->restored;
    }

    public function restoreWithResult(Page $page): PageRestoreResultData
    {
        // Re-read all candidates on each attempt: overlapping ancestor locks can deadlock.
        try {
            return $page->getConnection()->transaction(function () use ($page): PageRestoreResultData {
                $locked = SiteAccess::current()->query($page::class)->onlyTrashed()->whereKey($page->getKey())->lockForUpdate()->first();
                if (! $locked instanceof Page) {
                    return new PageRestoreResultData(false);
                }

                PageRestoreReadOnlyScope::run($locked->getConnection(), fn () => Gate::authorize('restore', $locked));
                if (! CanRestorePageCascadeAction::run($locked, lockForUpdate: true)) {
                    return new PageRestoreResultData(false);
                }

                $ids = CollectPageRestoreCascadeIdsAction::run($locked, lockForUpdate: true);
                $notice = BuildPageRestoreNoticeAction::run($locked, $ids);

                return new PageRestoreResultData($locked->restore(), $notice);
            }, attempts: 3);
        } catch (PageRestoreCancelledException) {
            return new PageRestoreResultData(false);
        }
    }
}
