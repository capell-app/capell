<?php

declare(strict_types=1);

namespace Capell\Admin\Data\SitePermissions;

use Spatie\LaravelData\Data;

final class SitePermissionChangeData extends Data
{
    public function __construct(
        public int $userId,
        public string $userName,
        public int $roleId,
        public string $roleName,
        public bool $losesSiteAccess = false,
        public bool $isActor = false,
    ) {}
}
