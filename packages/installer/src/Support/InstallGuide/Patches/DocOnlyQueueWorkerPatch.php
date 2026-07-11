<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Installer\Support\InstallGuide\Patch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use RuntimeException;

class DocOnlyQueueWorkerPatch implements Patch
{
    public function id(): string
    {
        return 'doc_only_queue_worker';
    }

    public function group(): string
    {
        return 'Manual steps';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.doc_only_queue_worker_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.doc_only_queue_worker_description');
    }

    public function docUrl(): ?string
    {
        return 'https://laravel.com/docs/queues';
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function probe(): PatchStatus
    {
        return PatchStatus::Unsupported;
    }

    public function reason(): ?string
    {
        return __('capell-installer::install-guide.doc_only_queue_worker_reason');
    }

    public function apply(): void
    {
        throw new RuntimeException(
            'DocOnlyQueueWorkerPatch cannot be applied. This step requires manual installer using Supervisor or a process manager.',
        );
    }
}
