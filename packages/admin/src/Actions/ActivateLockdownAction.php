<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Capell\Core\Support\Security\LockdownStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsObject;

final class ActivateLockdownAction
{
    use AsObject;

    public function __construct(
        private readonly LockdownStore $lockdownStore,
        private readonly LockdownStaticCacheSwitcher $staticCacheSwitcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Authenticatable $user): array
    {
        return $this->lockdownStore->activateFor(
            user: $user,
            staticCacheState: $this->staticCacheSwitcher->activate(),
        );
    }
}
