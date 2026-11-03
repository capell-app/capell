<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Data\Extensions\ExtensionQuarantineEvidenceData;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Support\Packages\ExtensionQuarantineGate;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Release one quarantined package, but only when it verifies now and the
 * digest comparison that caused the quarantine no longer fails.
 *
 * @method static ExtensionVerificationReportData run(string $composerName)
 */
final class ReleaseExtensionQuarantineAction
{
    use AsFake;
    use AsObject;

    public function handle(string $composerName): ExtensionVerificationReportData
    {
        $report = VerifyInstalledExtensionAction::run($composerName);
        throw_unless($report->passed, ExtensionVerificationFailedException::class, $report);

        $record = ExtensionQuarantine::query()->where('composer_name', $composerName)->first();
        if ($record instanceof ExtensionQuarantine && is_string($record->failing_comparison) && is_string($record->expected_digest)) {
            $this->refuseWhileEvidenceFails($record->failing_package, $record->failing_comparison, $record->expected_digest);
        }

        ExtensionQuarantine::query()->where('composer_name', $composerName)->delete();
        resolve(ExtensionQuarantineGate::class)->forget();

        return $report;
    }

    private function refuseWhileEvidenceFails(string $failingPackage, string $comparison, string $expectedDigest): void
    {
        $evidence = match ($comparison) {
            ExtensionQuarantineEvidenceData::CONTENT_SHA256 => VerifyInstalledExtensionAction::run($failingPackage, expectedContentHash: $expectedDigest),
            ExtensionQuarantineEvidenceData::DIST_CHECKSUM => VerifyInstalledExtensionAction::run($failingPackage, expectedDistSha: $expectedDigest),
            default => null,
        };

        if ($evidence instanceof ExtensionVerificationReportData && $evidence->passed && in_array($comparison, $evidence->comparisons, true)) {
            return;
        }

        $description = ExtensionVerificationIssueData::describe(ExtensionVerificationIssueData::QUARANTINE_EVIDENCE_STILL_FAILS, $failingPackage);

        throw new ExtensionVerificationFailedException(new ExtensionVerificationReportData(
            $failingPackage,
            $evidence?->installedVersion,
            null,
            null,
            false,
            [new ExtensionVerificationIssueData(ExtensionVerificationIssueData::QUARANTINE_EVIDENCE_STILL_FAILS, $description['message'], $description['next_action'])],
            [],
            CarbonImmutable::now(),
        ));
    }
}
