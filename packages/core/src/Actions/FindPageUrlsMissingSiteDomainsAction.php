<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, PageUrl> run()
 */
final class FindPageUrlsMissingSiteDomainsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, PageUrl>
     */
    public function handle(): Collection
    {
        return PageUrl::query()
            ->whereDoesntHave(
                'siteDomain',
                fn (Builder $query): Builder => $query->whereNull('site_domains.deleted_at'),
            )
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('sites')
                    ->whereColumn('sites.id', 'page_urls.site_id')
                    ->whereNull('sites.deleted_at');
            })
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('languages')
                    ->whereColumn('languages.id', 'page_urls.language_id')
                    ->whereNull('languages.deleted_at');
            })
            ->with(['language', 'site'])
            ->get();
    }
}
