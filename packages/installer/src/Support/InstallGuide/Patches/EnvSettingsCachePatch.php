<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use Capell\Installer\Support\Patching\EnvFileEditor;
use RuntimeException;
use Throwable;

class EnvSettingsCachePatch implements Patch
{
    private const string ENV_FILE_PATH = '.env';

    private const string SETTINGS_CACHE_KEY = 'SETTINGS_CACHE_ENABLED';

    private const string SETTINGS_CACHE_VALUE = 'true';

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

    public function probe(): PatchStatus
    {
        $envFilePath = base_path(self::ENV_FILE_PATH);

        if (! file_exists($envFilePath)) {
            return PatchStatus::Unsupported;
        }

        try {
            $editor = new EnvFileEditor($envFilePath);
            $currentValue = $editor->get(self::SETTINGS_CACHE_KEY);

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
        } catch (Throwable) {
            return PatchStatus::Unsupported;
        }
    }

    public function reason(): ?string
    {
        return null;
    }

    public function apply(): void
    {
        $envFilePath = base_path(self::ENV_FILE_PATH);

        throw_unless(file_exists($envFilePath), RuntimeException::class, '.env file not found at: ' . $envFilePath);

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(
                'Cannot apply patch when status is: ' . $status->value,
            );
        }

        try {
            $editor = new EnvFileEditor($envFilePath);
            $editor->backup();
            $editor->set(self::SETTINGS_CACHE_KEY, self::SETTINGS_CACHE_VALUE);
            $editor->save();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply EnvSettingsCachePatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }
}
