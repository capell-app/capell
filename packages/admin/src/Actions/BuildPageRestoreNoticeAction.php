<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageRestoreNoticeAction
{
    use AsFake;
    use AsObject;

    /** @param list<int> $ids */
    public function handle(Page $page, array $ids): ?string
    {
        $root = SiteAccess::current()->query($page::class)->onlyTrashed()->whereKey($ids)->orderBy($page->getLftName())->first();
        if (! $root instanceof Page) {
            return null;
        }

        $excludedIds = CollectPageRestoreCascadeIdsAction::make()->collectExcludedDescendantIds($root, $ids);
        $excluded = SiteAccess::current()->query($page::class)->onlyTrashed()->whereKey($excludedIds)
            ->orderBy($page->getLftName())->lockForUpdate()->get();
        $notices = [];
        if ($excluded->isNotEmpty()) {
            $notices[] = __('capell-admin::message.page_restore_excluded_descendants', [
                'pages' => $excluded->map(fn (Page $excludedPage): string => $excludedPage->name ?? '#' . $excludedPage->getKey())->implode(', '),
            ]);
        }

        $inaccessibleCount = count($excludedIds) - $excluded->count();
        if ($inaccessibleCount > 0) {
            $notices[] = trans_choice('capell-admin::message.page_restore_inaccessible_descendants', $inaccessibleCount, ['count' => $inaccessibleCount]);
        }

        return $notices === [] ? null : implode(' ', $notices);
    }
}
