<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Agent;

use Capell\Core\Data\Agent\AgentSearchResultData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Public search adapters must return only published, site-scoped page results. */
interface AgentPageSearch
{
    /** @return LengthAwarePaginator<int, AgentSearchResultData> */
    public function search(string $query, int $siteId, ?int $languageId, int $page = 1): LengthAwarePaginator;
}
