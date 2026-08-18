<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\PatchStatus;

class EnvQueueConnectionPatch extends AbstractEnvKeyPatch
{
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

    protected function key(): string
    {
        return 'QUEUE_CONNECTION';
    }

    protected function value(): string
    {
        return 'database';
    }

    protected function decideStatus(?string $currentValue): PatchStatus
    {
        // If key is missing or set to 'sync', it's applicable
        if ($currentValue === null || $currentValue === 'sync') {
            return PatchStatus::Applicable;
        }

        // If set to any other value (e.g., beanstalkd, sqs), treat as already applied
        return PatchStatus::AlreadyApplied;
    }
}
