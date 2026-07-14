<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\EnvFileEditor;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use RuntimeException;
use Throwable;

class EnvQueueConnectionPatch implements Patch
{
    private const string ENV_FILE_PATH = '.env';

    private const string QUEUE_CONNECTION_KEY = 'QUEUE_CONNECTION';

    private const string QUEUE_CONNECTION_VALUE = 'database';

    public function id(): string
    {
        return 'env-queue-connection-patch';
    }

    public function group(): string
    {
        return 'environment';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.env_queue_connection_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.env_queue_connection_patch_description');
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
            $currentValue = $editor->get(self::QUEUE_CONNECTION_KEY);

            // If key is missing or set to 'sync', it's applicable
            if ($currentValue === null || $currentValue === 'sync') {
                return PatchStatus::Applicable;
            }

            // If set to any other value (e.g., beanstalkd, sqs), treat as already applied
            return PatchStatus::AlreadyApplied;
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
            $editor->set(self::QUEUE_CONNECTION_KEY, self::QUEUE_CONNECTION_VALUE);
            $editor->save();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply EnvQueueConnectionPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }
}
