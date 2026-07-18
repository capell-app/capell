<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Models\PageUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PageUrlRedirectHitRecorder
{
    public function recordHit(PageUrl $pageUrl): void
    {
        if (! Schema::hasColumn('page_urls', 'hit_count') || ! Schema::hasColumn('page_urls', 'last_hit_at')) {
            return;
        }

        $pageUrl->newQuery()
            ->whereKey($pageUrl->getKey())
            ->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_hit_at' => CarbonImmutable::now(),
            ]);
    }
}
