<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;

/** @internal */
interface DoctorCheck
{
    public function check(bool $installSummary = false): DoctorCheckResultData;
}
