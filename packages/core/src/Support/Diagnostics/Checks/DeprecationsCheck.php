<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Extensions\DeprecationData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Extensions\Deprecations;

/**
 * Lists the deprecated surfaces this installation used while the doctor ran.
 * Advisory: the site still works today, but each entry stops working at its
 * removal.
 */
final class DeprecationsCheck extends AbstractDoctorCheck
{
    public function __construct(private readonly Deprecations $deprecations) {}

    protected function id(): string
    {
        return 'core.extensions.deprecations';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $label = (string) __('capell-core::health.deprecations.label');
        $recorded = $this->deprecations->all();

        if ($recorded === []) {
            return new DoctorCheckResultData(label: $label, passed: true, message: (string) __('capell-core::health.deprecations.none'), severity: $this->severity());
        }

        $messages = array_map(static fn (DeprecationData $deprecation): string => (string) __('capell-core::health.deprecations.entry', [
            'surface' => $deprecation->surface,
            'replacement' => $deprecation->replacement,
            'removal' => $deprecation->removal,
        ]), $recorded);

        return new DoctorCheckResultData(
            label: $label,
            passed: false,
            message: implode(' ', $messages),
            remediation: (string) __('capell-core::health.deprecations.remediation'),
            severity: $this->severity(),
            evidence: ['deprecations' => array_map(static fn (DeprecationData $deprecation): array => $deprecation->toArray(), $recorded)],
        );
    }
}
