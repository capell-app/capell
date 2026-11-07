<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

/**
 * The sites whose public-read rules the current request consulted: every
 * eligibility decision and every stamp handed out while it ran. One instance
 * per request (scoped). CapPublicReadResponseCaching reads it to bound how
 * long any cache may keep the response.
 *
 * @experimental
 */
final class PublicReadResponseStamps
{
    /** @var array<string, int|null> */
    private array $sites = [];

    /** A null site stands for rules on any site. */
    public function record(?int $siteId): void
    {
        $this->sites[$siteId === null ? 'any' : 'site:' . $siteId] = $siteId;
    }

    public function consulted(): bool
    {
        return $this->sites !== [];
    }

    /** @return list<int|null> */
    public function sites(): array
    {
        return array_values($this->sites);
    }
}
