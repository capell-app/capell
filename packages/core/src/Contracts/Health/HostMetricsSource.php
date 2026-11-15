<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Health;

use Capell\Core\Data\Diagnostics\HostSampleData;

interface HostMetricsSource
{
    /** A reading of the server this process runs on. */
    public function read(): HostSampleData;
}
