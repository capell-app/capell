<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Exceptions\PageRestoreCancelledException;
use Capell\Core\Models\Page;
use Capell\Core\Support\PageRestoreLifecycle;
use Closure;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RestorePageCascadeRecordsAction
{
    use AsFake;
    use AsObject;

    /** @param Closure(Page): bool $restoreMember */
    public function handle(Page $page, Closure $restoreMember): bool
    {
        try {
            return $page->getConnection()->transaction(function () use ($page, $restoreMember): bool {
                $current = $page->newQuery()->withTrashed()->whereKey($page->getKey())->lockForUpdate()->first();
                if (! $current instanceof Page || ! $current->trashed()) {
                    return false;
                }

                $page->setRawAttributes($current->getAttributes(), sync: true);
                $ids = CollectPageRestoreCascadeIdsAction::run($page, lockForUpdate: true);
                $members = $page->newQuery()->onlyTrashed()->whereKey($ids)->orderBy($page->getLftName())->lockForUpdate()->get();
                throw_unless(resolve(PageRestoreLifecycle::class)->supports($page), PageRestoreCancelledException::class);
                throw_unless(CanRestorePageMembersAction::run($members), PageRestoreCancelledException::class);
                AssertPageRestoreUrlsAvailableAction::run($members);
                RestorePageCascadeRelationsAction::run($page, $ids);

                // Keep model events, auditing and extension observers; standard relation work is batched above.
                foreach ($members as $member) {
                    RestorePageCascadeRelationsAction::make()->restoreAdditionalRelations($member);
                    throw_unless($restoreMember($member->is($page) ? $page : $member), PageRestoreCancelledException::class);
                }

                PrunePageDeletionMembershipAction::run($page, $ids, pageBatchesOnly: resolve(PageRestoreLifecycle::class)->preservesSiteHistory());

                return true;
            });
        } catch (PageRestoreCancelledException) {
            return false;
        }
    }
}
