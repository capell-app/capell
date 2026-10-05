<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Exceptions\PageRestoreCancelledException;
use Capell\Core\Models\Page;
use Closure;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RestorePageCascadeRecordsAction
{
    use AsFake;
    use AsObject;

    /** @param Closure(Page): bool $restoreMember */
    public function handle(Page $page, Closure $restoreMember, bool $pageBatchesOnly = false): bool
    {
        try {
            return $page->getConnection()->transaction(function () use ($page, $restoreMember, $pageBatchesOnly): bool {
                $current = $page->newQuery()->withTrashed()->whereKey($page->getKey())->lockForUpdate()->first();
                if (! $current instanceof Page || ! $current->trashed()) {
                    return false;
                }

                $page->setRawAttributes($current->getAttributes(), sync: true);
                $ids = CollectPageRestoreCascadeIdsAction::run($page, lockForUpdate: true);
                $members = $page->newQuery()->onlyTrashed()->whereKey($ids)->orderBy($page->getLftName())->lockForUpdate()->get();
                throw_unless(CanRestorePageMembersAction::run($members), PageRestoreCancelledException::class);
                $recomputedIds = CollectPageRestoreCascadeIdsAction::run($page, lockForUpdate: true);
                throw_if(array_diff($recomputedIds, $ids) !== [] || array_diff($ids, $recomputedIds) !== [], PageRestoreCancelledException::class);
                AssertPageRestoreUrlsAvailableAction::run($members);
                RestorePageCascadeRelationsAction::run($page, $ids);

                // Keep model events, auditing and extension observers; standard relation work is batched above.
                foreach ($members as $member) {
                    RestorePageCascadeRelationsAction::make()->restoreAdditionalRelations($member);
                    throw_unless($restoreMember($member->is($page) ? $page : $member), PageRestoreCancelledException::class);
                }

                // Policies and listeners are normal application callbacks; re-read their persisted outcome.
                $currentMembers = $page->newQuery()->withTrashed()->whereKey($ids)->lockForUpdate()->get();
                throw_unless($currentMembers->count() === count($ids)
                    && ! $currentMembers->contains(fn (Page $member): bool => $member->trashed())
                    && CanRestorePageMembersAction::run($currentMembers), PageRestoreCancelledException::class);
                PrunePageDeletionMembershipAction::run($page, $ids, pageBatchesOnly: $pageBatchesOnly);

                return true;
            });
        } catch (PageRestoreCancelledException) {
            return false;
        }
    }
}
