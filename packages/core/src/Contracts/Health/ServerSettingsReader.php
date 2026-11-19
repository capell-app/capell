<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Health;

use Capell\Core\Data\Diagnostics\ServerSettingsData;

interface ServerSettingsReader
{
    /** The measured PHP, FPM, queue and memory settings of the server this process runs on. */
    public function read(): ServerSettingsData;
}
