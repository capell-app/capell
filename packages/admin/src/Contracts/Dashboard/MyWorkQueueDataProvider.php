<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\MyWorkQueueData;
use Illuminate\Contracts\Auth\Authenticatable;

interface MyWorkQueueDataProvider
{
    public function build(Authenticatable $user, int $limit): MyWorkQueueData;
}
