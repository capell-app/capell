<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Reporting;

use Capell\Core\Data\Reporting\RedactedSignalData;

/** @experimental */
interface Reporter
{
    public function report(RedactedSignalData $signal): void;
}
