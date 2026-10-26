<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageMoveTargetOptionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Page>  $pages
     * @return array<int, string>
     */
    public function handle(Collection $pages, User $actor, string $search = ''): array
    {
        $options = [];
        $siteIds = $pages->pluck('site_id')->unique()->all();
        $targets = SiteAccess::forActor($actor)->query(Page::class)
            ->whereIn('site_id', $siteIds)
            ->where('name', 'like', '%' . $search . '%')
            ->orderBy('name')
            ->cursor();

        foreach ($targets as $target) {
            if (! Gate::forUser($actor)->allows('view', $target)) {
                continue;
            }

            foreach ($pages as $page) {
                if (ResolvePageMoveSkipReasonAction::run($page, $target, $actor) === null) {
                    $options[(int) $target->getKey()] = $target->name;
                    break;
                }
            }

            if (count($options) === 50) {
                break;
            }
        }

        return $options;
    }
}
