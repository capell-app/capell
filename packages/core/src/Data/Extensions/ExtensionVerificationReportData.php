<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ExtensionVerificationReportData extends Data
{
    /**
     * @param  list<ExtensionVerificationIssueData>  $failures
     * @param  list<ExtensionVerificationIssueData>  $warnings
     * @param  list<string>  $comparisons
     */
    public function __construct(
        public readonly string $composerName,
        public readonly ?string $installedVersion,
        public readonly ?string $expectedVersionConstraint,
        public readonly ?string $previousVersion,
        public readonly bool $passed,
        public readonly array $failures,
        public readonly array $warnings,
        public readonly CarbonImmutable $checkedAt,
        public readonly ?string $distSha = null,
        public readonly ?string $distReference = null,
        public readonly ?string $distHost = null,
        public readonly ?string $installPath = null,
        public readonly int $omittedIssueCount = 0,
        public readonly array $comparisons = [],
        public readonly ?string $contentHash = null,
    ) {}
}
