<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Records that one visitor was seen on one site, in one language, on one UTC day.
 *
 * Nothing identifying is persisted. The stored value is a truncated HMAC of the
 * site, address and user agent, keyed by a salt derived from the application key
 * and the UTC day. The salt is derived rather than cached so it is identical on
 * every node and survives a cache flush: a per-node or mid-day salt would hash
 * one visitor several ways and inflate unique counts.
 */
final class RecordActivityVisitorAction
{
    public function execute(
        Site $site,
        string $language,
        ?string $ip,
        ?string $userAgent,
        ?CarbonImmutable $now = null,
    ): bool {
        $now ??= CarbonImmutable::now('UTC');
        $now = $now->utc();
        $day = $now->toDateString();
        $hash = $this->hash((int) $site->getKey(), $ip, $userAgent, $day);
        $flag = 'capell.activity.visitor:' . $hash;

        if (Cache::has($flag)) {
            return false;
        }

        $inserted = ActivityVisitor::query()->insertOrIgnore([
            'site_id' => $site->getKey(),
            'language' => $language,
            'day' => $day,
            'visitor_hash' => $hash,
            'first_seen_at' => $now->toDateTimeString(),
        ]) > 0;

        // Purely an optimisation: a cold or cleared cache costs one ignored
        // insert, never a duplicate row, because the unique index decides.
        Cache::put($flag, true, $now->endOfDay()->getTimestamp() - $now->getTimestamp() + 1);

        return $inserted;
    }

    public function hash(int $siteId, ?string $ip, ?string $userAgent, string $day): string
    {
        $salt = hash_hmac('sha256', $day, (string) config('app.key'));
        $subject = $siteId . '|' . ($ip ?? 'unknown') . '|' . ($userAgent ?? 'unknown');

        return substr(hash_hmac('sha256', $subject, $salt), 0, 32);
    }
}
