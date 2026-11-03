<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

use Spatie\LaravelData\Data;

/**
 * The comparison that failed when a package set was quarantined: which digest
 * kind was compared, what the release promised and what was on disk.
 */
final class ExtensionQuarantineEvidenceData extends Data
{
    public const string CONTENT_SHA256 = 'content_sha256';

    public const string DIST_CHECKSUM = 'dist_checksum';

    public function __construct(
        public readonly string $comparison,
        public readonly string $expectedDigest,
        public readonly ?string $actualDigest,
    ) {}

    /** The failing comparison in a report, or null when no digest comparison failed. */
    public static function fromReport(ExtensionVerificationReportData $report, ?string $expectedDistSha, ?string $expectedContentHash): ?self
    {
        $codes = array_map(static fn (ExtensionVerificationIssueData $issue): string => $issue->code, $report->failures);

        if ($expectedContentHash !== null && in_array(ExtensionVerificationIssueData::CONTENT_HASH_MISMATCH, $codes, true)) {
            return new self(self::CONTENT_SHA256, strtolower($expectedContentHash), $report->contentHash);
        }

        if ($expectedDistSha !== null && array_intersect([ExtensionVerificationIssueData::CHECKSUM_MISMATCH, ExtensionVerificationIssueData::CHECKSUM_MISSING], $codes) !== []) {
            return new self(self::DIST_CHECKSUM, $expectedDistSha, $report->distSha);
        }

        return null;
    }
}
