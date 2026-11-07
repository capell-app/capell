<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Support\Extensions\ExtensionIntegrityVerifier;
use Capell\Core\Support\Manifest\ManifestValidator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static ExtensionVerificationReportData run(string $composerName, ?string $expectedVersionConstraint = null, ?string $previousVersion = null, ?string $expectedSlug = null, ?string $expectedDistSha = null, ?string $releaseChecksumVersion = null, ?string $expectedContentHash = null) */
class VerifyInstalledExtensionAction
{
    use AsFake;
    use AsObject;

    public function handle(string $composerName, ?string $expectedVersionConstraint = null, ?string $previousVersion = null, ?string $expectedSlug = null, ?string $expectedDistSha = null, ?string $releaseChecksumVersion = null, ?string $expectedContentHash = null): ExtensionVerificationReportData
    {
        return new ExtensionIntegrityVerifier(base_path('vendor'), resolve(ManifestValidator::class))
            ->verify($composerName, $expectedVersionConstraint, $previousVersion, $expectedSlug, $expectedDistSha, $releaseChecksumVersion, expectedContentHash: $expectedContentHash);
    }
}
