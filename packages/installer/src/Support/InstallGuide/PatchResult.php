<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

class PatchResult
{
    public function __construct(
        public string $patchId,
        public string $label,
        public PatchStatus $statusBefore,
        public PatchStatus $statusAfter,
        public ?string $errorMessage = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->statusAfter === PatchStatus::AlreadyApplied
            && $this->statusBefore !== PatchStatus::AlreadyApplied;
    }

    public function skipped(): bool
    {
        return $this->statusBefore === $this->statusAfter
            && $this->statusAfter !== PatchStatus::AlreadyApplied;
    }

    public function failed(): bool
    {
        return $this->errorMessage !== null;
    }
}
