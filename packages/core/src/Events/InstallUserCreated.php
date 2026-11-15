<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dispatched once for each starter account the installer creates, after its
 * role has been assigned, so packages that gate access separately from Capell
 * roles (such as the Admin panel) can finish setting the account up.
 */
final readonly class InstallUserCreated
{
    public function __construct(
        public Authenticatable $user,
        public ?string $roleName,
        public ?Site $site = null,
    ) {}
}
