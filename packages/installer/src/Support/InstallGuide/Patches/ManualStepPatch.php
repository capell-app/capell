<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use RuntimeException;

/**
 * A documentation-only install guide step: nothing to probe or apply, just a
 * pointer to a manual configuration task the customer must do themselves
 * (web server, queue worker, an optional package swap). Every instance shares
 * the same "Manual steps" group, disabled-by-default, always-Unsupported,
 * throw-on-apply shape — only the id/copy/doc link differ.
 */
final class ManualStepPatch implements Patch
{
    private function __construct(
        private readonly string $id,
        private readonly string $labelKey,
        private readonly string $descriptionKey,
        private readonly string $reasonKey,
        private readonly ?string $docUrl,
        private readonly string $unapplicableMessage,
    ) {}

    public static function webServerConfig(): self
    {
        return new self(
            id: 'doc_only_web_server',
            labelKey: 'capell-installer::install-guide.doc_only_web_server_label',
            descriptionKey: 'capell-installer::install-guide.doc_only_web_server_description',
            reasonKey: 'capell-installer::install-guide.doc_only_web_server_reason',
            docUrl: 'https://docs.capell.app/packages/frontend/server-config/',
            unapplicableMessage: 'DocOnlyWebServerPatch cannot be applied. This step requires manual web server configuration (Apache/Nginx).',
        );
    }

    public static function queueWorker(): self
    {
        return new self(
            id: 'doc_only_queue_worker',
            labelKey: 'capell-installer::install-guide.doc_only_queue_worker_label',
            descriptionKey: 'capell-installer::install-guide.doc_only_queue_worker_description',
            reasonKey: 'capell-installer::install-guide.doc_only_queue_worker_reason',
            docUrl: 'https://docs.capell.app/operations/troubleshooting/#queue-worker',
            unapplicableMessage: 'DocOnlyQueueWorkerPatch cannot be applied. This step requires manual installer using Supervisor or a process manager.',
        );
    }

    public static function mediaLibrary(): self
    {
        return new self(
            id: 'doc_only_media_library',
            labelKey: 'capell-installer::install-guide.doc_only_media_library_label',
            descriptionKey: 'capell-installer::install-guide.doc_only_media_library_description',
            reasonKey: 'capell-installer::install-guide.doc_only_media_library_reason',
            docUrl: 'https://docs.capell.app/packages/#media-backends',
            unapplicableMessage: 'DocOnlyMediaLibraryPatch cannot be applied. Install capell-app/media-library package and run the migration command.',
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function group(): string
    {
        return 'Manual steps';
    }

    public function label(): string
    {
        return __($this->labelKey);
    }

    public function description(): string
    {
        return __($this->descriptionKey);
    }

    public function docUrl(): ?string
    {
        return $this->docUrl;
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function probe(): PatchStatus
    {
        return PatchStatus::Unsupported;
    }

    public function reason(): string
    {
        return __($this->reasonKey);
    }

    public function apply(): void
    {
        throw new RuntimeException($this->unapplicableMessage);
    }
}
