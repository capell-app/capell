<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SecurityScanResultData extends Data
{
    /**
     * @param  list<string>  $failures  Checks, sources or analysers that threw; the scan continues past them.
     */
    public function __construct(
        public readonly CarbonImmutable $scannedAt,
        public readonly int $checksRun,
        public readonly int $advisorySourcesRun,
        public readonly ?string $analyserUsed,
        public readonly int $newFindings,
        public readonly int $reopenedFindings,
        public readonly int $resolvedFindings,
        public readonly int $activeFindings,
        public readonly ?SecurityWeaknessSeverity $highestActiveSeverity,
        public readonly array $failures = [],
    ) {}
}
