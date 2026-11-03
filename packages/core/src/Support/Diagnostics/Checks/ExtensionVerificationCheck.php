<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Override;

final class ExtensionVerificationCheck implements DoctorCheck
{
    #[Override]
    public function check(bool $installSummary = false): DoctorCheckResultData
    {
        $issues = [];
        if (ExtensionQuarantine::quarantinedNames() !== []) {
            foreach (ExtensionQuarantine::query()->orderBy('composer_name')->get() as $quarantine) {
                $issues[] = (string) __('capell-core::extensions.quarantine.doctor', [
                    'package' => $quarantine->composer_name,
                    'failing' => $quarantine->failing_package,
                    'set' => implode(', ', $quarantine->quarantined_set),
                ]);
            }
        }

        foreach (CapellCore::getPackages(withoutCore: false) as $package) {
            if (! $package instanceof PackageData) {
                continue;
            }

            if (! $package->isInstalled()) {
                continue;
            }

            if (TrustedCorePackages::contains($package->name)) {
                continue;
            }

            $report = VerifyInstalledExtensionAction::run($package->name);
            $first = $report->failures[0] ?? null;
            if ($first !== null) {
                $message = $first->code === ExtensionVerificationIssueData::MANIFEST_INVALID
                    ? __('capell-core::extensions.verification.manifest_invalid_safe', ['package' => $package->name])
                    : $first->message;
                $issues[] = $package->name . ' → ' . $message . ' → ' . $first->nextAction;
            }
        }

        return new DoctorCheckResultData(
            label: __('capell-core::health.extension_verification.label'),
            passed: $issues === [],
            message: $issues === []
                ? __('capell-core::health.extension_verification.passed')
                : __('capell-core::health.extension_verification.failed', ['issues' => implode('; ', $issues)]),
            id: 'core.packages.extension-verification',
            severity: DoctorCheckSeverity::Warning,
        );
    }
}
