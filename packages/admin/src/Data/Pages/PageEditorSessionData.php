<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class PageEditorSessionData extends Data
{
    public function __construct(
        public readonly string $heartbeatUrl,
        public readonly string $releaseUrl,
        public readonly ?string $csrfToken,
        public readonly bool $initialConflict,
        public readonly int $pageId,
        public readonly string $storageKey,
    ) {}

    /**
     * @return array{
     *     heartbeatUrl: string,
     *     releaseUrl: string,
     *     csrfToken: string|null,
     *     intervalMs: int,
     *     initialConflict: bool,
     *     pageId: int,
     *     storageKey: string,
     *     formSelector: string,
     *     localDraftDebounceMs: int,
     *     localDraftTtlMs: int,
     *     localDraftVersion: int
     * }
     */
    public function configuration(): array
    {
        return [
            'heartbeatUrl' => $this->heartbeatUrl,
            'releaseUrl' => $this->releaseUrl,
            'csrfToken' => $this->csrfToken,
            'intervalMs' => 30_000,
            'initialConflict' => $this->initialConflict,
            'pageId' => $this->pageId,
            'storageKey' => $this->storageKey,
            'formSelector' => '#form',
            'localDraftDebounceMs' => 750,
            'localDraftTtlMs' => 86_400_000,
            'localDraftVersion' => 1,
        ];
    }
}
