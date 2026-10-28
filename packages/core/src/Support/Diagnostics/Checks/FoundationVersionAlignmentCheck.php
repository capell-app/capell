<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Override;

final class FoundationVersionAlignmentCheck extends AbstractDoctorCheck
{
    private const array PACKAGES = [
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/installer',
        'capell-app/marketplace',
    ];

    #[Override]
    protected function id(): string
    {
        return 'core.packages.foundation-version-alignment';
    }

    #[Override]
    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    #[Override]
    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $versions = array_intersect_key(ResolveInstalledComposerVersionsAction::run(), array_flip(self::PACKAGES));
        $comparable = array_filter($versions, static fn (string $version): bool => preg_match('/^v?\d+\.\d+\.\d+$/', $version) === 1);
        $unknown = array_diff_key($versions, $comparable);
        $normalised = array_map(static fn (string $version): string => ltrim($version, 'v'), $comparable);
        $mismatch = count(array_unique($normalised)) > 1;

        return new DoctorCheckResultData(
            label: 'Foundation package version alignment',
            passed: ! $mismatch,
            message: $mismatch
                ? 'Installed foundation package releases are not aligned.'
                : ($unknown !== [] || count($comparable) < 2
                    ? 'Version alignment could not be established for all installed foundation packages.'
                    : 'Installed foundation package releases are aligned.'),
            remediation: $mismatch ? 'Update the foundation packages together with Composer, including dependencies.' : null,
            evidence: ['versions' => $versions, 'unknown' => array_keys($unknown)],
        );
    }
}
