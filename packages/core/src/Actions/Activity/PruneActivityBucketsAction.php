<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Models\ActivityBucket;
use Carbon\CarbonImmutable;

final class PruneActivityBucketsAction
{
    public function __construct(private readonly ActivitySettingsReader $settings) {}

    public function execute(?int $days = null, bool $pretend = false): int
    {
        $days = max(1, min(7, $days ?? $this->settings->retentionDays()));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $query = ActivityBucket::query()->where('bucket_started_at', '<', $cutoff);

        if ($pretend) {
            return $query->count();
        }

        return $query->delete();
    }
}
