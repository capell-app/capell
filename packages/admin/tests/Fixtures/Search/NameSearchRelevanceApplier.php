<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Search;

use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Capell\Core\Models\Layout;
use Illuminate\Database\Eloquent\Builder;

final class NameSearchRelevanceApplier
{
    use AppliesNameSearchRelevance;

    /**
     * @param  Builder<Layout>  $query
     * @return Builder<Layout>
     */
    public function apply(Builder $query, string $search): Builder
    {
        return self::applyNameSearchRelevance($query, $search);
    }
}
