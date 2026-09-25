<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Reporting;

use Capell\Core\Data\Reporting\SignalData;

interface Reporter
{
    public function report(SignalData $signal): void;
}
