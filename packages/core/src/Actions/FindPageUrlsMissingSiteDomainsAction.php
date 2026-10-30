<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\PageUrl;
use Capell\Core\Support\TableName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, PageUrl> run()
 */
class FindPageUrlsMissingSiteDomainsAction
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
                fn (Builder $query): Builder => $query->whereNull(TableName::resolve('site_domains') . '.deleted_at'),
            )
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from(TableName::resolve('sites'))
                    ->whereColumn(TableName::resolve('sites') . '.id', TableName::resolve('page_urls') . '.site_id')
                    ->whereNull(TableName::resolve('sites') . '.deleted_at');
            })
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from(TableName::resolve('languages'))
                    ->whereColumn(TableName::resolve('languages') . '.id', TableName::resolve('page_urls') . '.language_id')
                    ->whereNull(TableName::resolve('languages') . '.deleted_at');
            })
            ->with(['language', 'site'])
            ->get();
    }
}
