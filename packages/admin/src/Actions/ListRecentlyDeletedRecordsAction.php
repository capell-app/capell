<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Policies\MediaPolicy;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Policies\RecentlyDeletedListingPolicy;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\Gate as ListingGate;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListRecentlyDeletedRecordsAction
{
    use AsFake;
    use AsObject;

    /** @return LengthAwarePaginator<int, Model> */
    public function handle(string $resource, string $search, int $page): LengthAwarePaginator
    {
        $model = $resource === 'page' ? Page::class : Media::class;
        $query = SiteAccess::current()->query($model)->onlyTrashed()->latest('deleted_at')->orderByDesc('id');
        if ($resource === 'page') {
            $query->with(['blueprint.roleRestrictions', 'site']);
        }

        // Authorise before counting or slicing: restricted rows must not consume
        // a page or disclose their existence through the result count.
        $records = [];
        $total = 0;
        $page = max(1, $page);
        foreach ($query->lazy(200)->chunk(200) as $batch) {
            $gate = $this->listingGate($model, new Collection($batch->all()));
            foreach ($batch as $record) {
                if (! $gate->allows('view', $record)) {
                    continue;
                }

                if ($search !== '' && mb_stripos((string) ($record->getAttribute('name') ?? $record->getAttribute('file_name')), $search) === false) {
                    continue;
                }

                if ($total >= ($page - 1) * 25 && count($records) < 25) {
                    $records[] = $record;
                }

                $total++;
            }
        }

        return new LengthAwarePaginator($records, $total, 25, $page, [
            'pageName' => $resource . 'Page',
        ]);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Collection<int, Model>  $records
     */
    private function listingGate(string $model, Collection $records): GateContract
    {
        $actor = auth()->user();
        $gate = Gate::forUser($actor);
        $policy = $gate->getPolicyFor($model);
        if (! $gate instanceof ListingGate || $gate::class !== ListingGate::class || ! $actor instanceof User
            || (! $policy instanceof MediaPolicy || $policy::class !== MediaPolicy::class)
                && (! $policy instanceof PagePolicy || $policy::class !== PagePolicy::class)) {
            return $gate;
        }

        // A private Gate preserves every per-record before/after hook, while
        // custom policies (including subclasses) retain their normal view path.
        // Its container and view-only snapshot cannot leak into mutation checks.
        $container = new Container;
        $container->instance(Dispatcher::class, resolve(Dispatcher::class));
        $container->instance(RecentlyDeletedListingPolicy::class, RecentlyDeletedListingPolicy::forScopedRecords($actor, $records, $policy));

        $gate->setContainer($container);
        $gate->policy($model, RecentlyDeletedListingPolicy::class);

        return $gate;
    }
}
