<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Closure;
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
    /**
     * @param  Closure(): string  $label
     * @param  Closure(): string  $description
     * @param  Closure(): string  $reason
     */
    private function __construct(
        private readonly string $id,
        private readonly Closure $label,
        private readonly Closure $description,
        private readonly Closure $reason,
        private readonly ?string $docUrl,
        private readonly string $unapplicableMessage,
    ) {}

    public static function webServerConfig(): self
    {
        return new self(
            id: 'doc_only_web_server',
            label: static fn (): string => __('capell-installer::install-guide.doc_only_web_server_label'),
            description: static fn (): string => __('capell-installer::install-guide.doc_only_web_server_description'),
            reason: static fn (): string => __('capell-installer::install-guide.doc_only_web_server_reason'),
            docUrl: 'https://docs.capell.app/packages/frontend/server-config/',
            unapplicableMessage: 'DocOnlyWebServerPatch cannot be applied. This step requires manual web server configuration (Apache/Nginx).',
        );
    }

    public static function queueWorker(): self
    {
        return new self(
            id: 'doc_only_queue_worker',
            label: static fn (): string => __('capell-installer::install-guide.doc_only_queue_worker_label'),
            description: static fn (): string => __('capell-installer::install-guide.doc_only_queue_worker_description'),
            reason: static fn (): string => __('capell-installer::install-guide.doc_only_queue_worker_reason'),
            docUrl: 'https://docs.capell.app/operations/troubleshooting/#queue-worker',
            unapplicableMessage: 'DocOnlyQueueWorkerPatch cannot be applied. This step requires manual installer using Supervisor or a process manager.',
        );
    }

    public static function mediaLibrary(): self
    {
        return new self(
            id: 'doc_only_media_library',
            label: static fn (): string => __('capell-installer::install-guide.doc_only_media_library_label'),
            description: static fn (): string => __('capell-installer::install-guide.doc_only_media_library_description'),
            reason: static fn (): string => __('capell-installer::install-guide.doc_only_media_library_reason'),
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
        return ($this->label)();
    }

    public function description(): string
    {
        return ($this->description)();
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
        return ($this->reason)();
    }

    public function apply(): void
    {
        throw new RuntimeException($this->unapplicableMessage);
    }
}
