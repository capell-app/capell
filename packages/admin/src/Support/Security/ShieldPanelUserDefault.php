<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Security;

use Illuminate\Contracts\Config\Repository;

/**
 * Filament Shield's own default hands the panel user role to every User row created in a web
 * request, which on a Capell install would admit registrations, customer accounts and imports
 * to the admin panel. Capell admits people deliberately (invitations, the installer and the
 * Users resource), so unless the host application's own published Shield config states a
 * choice, the role is not handed out automatically.
 */
final readonly class ShieldPanelUserDefault
{
    public function __construct(private Repository $config) {}

    /** Returns true when Capell's default was applied, false when the host chose for itself. */
    public function apply(string $hostConfigPath): bool
    {
        if ($this->hostChoseAValue($hostConfigPath)) {
            return false;
        }

        $this->config->set('filament-shield.panel_user.enabled', false);

        return true;
    }

    private function hostChoseAValue(string $hostConfigPath): bool
    {
        if (! is_file($hostConfigPath)) {
            return false;
        }

        $host = require $hostConfigPath;

        return is_array($host)
            && is_array($host['panel_user'] ?? null)
            && array_key_exists('enabled', $host['panel_user']);
    }
}
