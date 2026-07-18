<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

abstract class AbstractDoctorCheck implements DoctorCheck
{
    abstract protected function id(): string;

    abstract protected function severity(): DoctorCheckSeverity;

    abstract protected function run(bool $installSummary): DoctorCheckResultData;

    final public function check(bool $installSummary = false): DoctorCheckResultData
    {
        $check = $this->run($installSummary);

        return new DoctorCheckResultData(
            label: $check->label,
            passed: $check->passed,
            message: $check->message,
            remediation: $check->remediation,
            id: $this->id(),
            severity: $this->severity(),
            evidence: $check->evidence,
        );
    }
}
