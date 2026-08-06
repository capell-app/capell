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
}
