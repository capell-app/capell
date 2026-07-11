<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\ContentHealthData;

interface ContentHealthDataProvider
{
    public function build(): ContentHealthData;
}
