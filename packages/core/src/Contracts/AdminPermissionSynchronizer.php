<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface AdminPermissionSynchronizer
{
    public function hasBootedPanel(): bool;

    public function syncForInstall(): void;
}
