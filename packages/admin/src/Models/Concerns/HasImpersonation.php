<?php

declare(strict_types=1);

namespace Capell\Admin\Models\Concerns;

use Capell\Admin\Enums\CapellPermission;

trait HasImpersonation
{
    public function canImpersonate(): bool
    {
        return $this->checkPermissionTo(CapellPermission::ImpersonateUsers->name());
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->checkPermissionTo(CapellPermission::ImpersonateUsers->name())
            && ! $this->isPrivilegedImpersonationTarget();
    }

    /**
     * Impersonation assumes the target's session without satisfying that
     * account's own two-factor requirement, so a higher-trust account must never
     * be impersonable: block super admins and extension authors regardless of
     * whether they hold the impersonate permission themselves.
     */
    private function isPrivilegedImpersonationTarget(): bool
    {
        if ($this->isGlobalAdmin()) {
            return true;
        }

        return method_exists($this, 'authorProfile')
            && $this->authorProfile()->exists();
    }
}
