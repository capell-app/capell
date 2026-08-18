<?php

declare(strict_types=1);

namespace Capell\Core\Support\Activity;

use Capell\Core\Contracts\ActivitySettingsReader;

final class DefaultActivitySettingsReader implements ActivitySettingsReader
{
    public function collectionEnabled(): bool
    {
        return (bool) config('capell.analytics.collection_enabled', true);
    }

    public function searchCollectionEnabled(): bool
    {
        return (bool) config('capell.analytics.search_collection_enabled', false);
    }

    public function retentionDays(): int
    {
        return max(1, min(7, (int) config('capell.analytics.activity_retention_days', 1)));
    }

    /**
     * Visitor-day rows outlive raw activity buckets: the dashboard compares the
     * last seven days against the seven before them, and a multi-day unique
     * count cannot be rebuilt from daily rollups.
     */
    public function visitorRetentionDays(): int
    {
        return max(14, min(400, (int) config('capell.analytics.visitor_retention_days', 30)));
    }
}
