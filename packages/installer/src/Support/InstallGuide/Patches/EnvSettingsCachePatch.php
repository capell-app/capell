<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\PatchStatus;

class EnvSettingsCachePatch extends AbstractEnvKeyPatch
{
    public function id(): string
    {
        return 'env-settings-cache-patch';
    }

    public function group(): string
    {
        return 'environment';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.env_settings_cache_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.env_settings_cache_patch_description');
    }

    public function docUrl(): ?string
    {
        return null;
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    protected function key(): string
    {
        return 'SETTINGS_CACHE_ENABLED';
    }

    protected function value(): string
    {
        return 'true';
    }

    protected function decideStatus(?string $currentValue): PatchStatus
    {
        // If key is missing, it's applicable
        if ($currentValue === null) {
            return PatchStatus::Applicable;
        }

        // If set to 'true', already applied
        if ($currentValue === 'true') {
            return PatchStatus::AlreadyApplied;
        }

        // If set to any other value (e.g., 'false'), treat as customized
        return PatchStatus::Customised;
    }
}
