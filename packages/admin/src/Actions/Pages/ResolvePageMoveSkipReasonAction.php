<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePageMoveSkipReasonAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, Page $newParent, User $actor): ?string
    {
        if (Gate::forUser($actor)->inspect('update', $newParent)->denied()) {
            return 'target_parent_denied';
        }

        if ($this->wouldCrossSites($page, $newParent)) {
            return 'cross_sites';
        }

        if ($this->wouldCreateCycle($page, $newParent, $actor)) {
            return 'cycle';
        }

        if (Gate::forUser($actor)->inspect('update', $page)->denied()) {
            return 'unauthorized';
        }

        return null;
    }

    private function wouldCreateCycle(Page $page, Page $newParent, User $actor): bool
    {
        if ($page->is($newParent)) {
            return true;
        }

        $pageKey = $page->getKey();
        $current = $newParent;

        while ($current->parent_id !== null) {
            if ($current->parent_id === $pageKey) {
                return true;
            }

            $current = SiteAccess::forActor($actor)->query(Page::class)->find($current->parent_id);

            if ($current === null) {
                return false;
            }
        }

        return false;
    }

    private function wouldCrossSites(Page $page, Page $newParent): bool
    {
        return $page->site_id !== $newParent->site_id;
    }
}
