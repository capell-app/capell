<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Capell\Core\Support\Security\LockdownStore;
use Lorisleiva\Actions\Concerns\AsObject;

final class DeactivateLockdownAction
{
    use AsObject;

    public function __construct(
        private readonly LockdownStore $lockdownStore,
        private readonly LockdownStaticCacheSwitcher $staticCacheSwitcher,
    ) {}

    public function handle(): void
    {
        $lockdownData = $this->lockdownStore->data();

        $this->staticCacheSwitcher->deactivate($lockdownData);
        $this->lockdownStore->deactivate();
    }
}
