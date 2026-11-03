<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Capell\Core\Support\Security\LockdownStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class ActivateLockdownAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly LockdownStore $lockdownStore,
        private readonly LockdownStaticCacheSwitcher $staticCacheSwitcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Authenticatable $user, ?string $guard = null): array
    {
        // Resolve the identity scope before touching the static cache so a misconfigured guard changes nothing.
        throw_if($this->lockdownStore->userProviderForGuard($guard) === null, RuntimeException::class, 'Unable to identify the lockdown user provider.');

        return $this->lockdownStore->activateFor(
            user: $user,
            staticCacheState: config('capell.multi_node', false) ? ['skipped' => 'multi_node'] : $this->staticCacheSwitcher->activate(),
            guard: $guard,
        );
    }
}
