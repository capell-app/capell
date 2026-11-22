<?php

declare(strict_types=1);

namespace Capell\Admin\Models\Concerns;

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Support\Impersonation\ImpersonationCeiling;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use ReflectionMethod;

trait HasImpersonation
{
    public function canImpersonate(): bool
    {
        return $this->checkPermissionTo(CapellPermission::ImpersonateUsers->name());
    }

    /**
     * The package calls this without the actor, both to show the action and
     * again when it runs, so the actor's ceiling is enforced here for both.
     */
    public function canBeImpersonated(?Authenticatable $actor = null): bool
    {
        if ($this->checkPermissionTo(CapellPermission::ImpersonateUsers->name()) || $this->isPrivilegedImpersonationTarget()) {
            return false;
        }

        $actor ??= Filament::auth()->user() ?? auth()->user();

        return $actor instanceof Authenticatable
            && resolve(ImpersonationCeiling::class)->permits($actor, $this);
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

        if (! in_array('authorProfile', get_class_methods($this), true)) {
            return false;
        }

        $authorProfile = new ReflectionMethod($this, 'authorProfile')->invoke($this);

        if (! is_object($authorProfile) || ! method_exists($authorProfile, 'exists')) {
            return false;
        }

        return new ReflectionMethod($authorProfile, 'exists')->invoke($authorProfile) === true;
    }
}
