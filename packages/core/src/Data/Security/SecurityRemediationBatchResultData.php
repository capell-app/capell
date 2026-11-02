<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Spatie\LaravelData\Data;

final class SecurityRemediationBatchResultData extends Data
{
    /**
     * @param  list<int>  $runIds  SecurityRemediationRun ids recorded by this batch (empty on a dry run).
     * @param  array<string, string>  $skipped  weakness id or fingerprint => why nothing was done
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $considered,
        public readonly int $applied,
        public readonly int $failed,
        public readonly array $runIds = [],
        public readonly array $skipped = [],
    ) {}
}
