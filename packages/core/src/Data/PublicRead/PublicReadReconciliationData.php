<?php

declare(strict_types=1);

namespace Capell\Core\Data\PublicRead;

/**
 * What one public-read reconciliation run did: the withdrawal steps it ran
 * successfully and those that failed (retried on the next run).
 *
 * @experimental
 */
final readonly class PublicReadReconciliationData
{
    /**
     * @param  list<string>  $withdrawn  "step (scope)" labels
     * @param  array<string, string>  $failures  failure messages keyed by "step (scope)"
     */
    public function __construct(
        public array $withdrawn,
        public array $failures,
    ) {}

    public function succeeded(): bool
    {
        return $this->failures === [];
    }
}
