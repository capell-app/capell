<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

use Capell\Core\Support\Patching\PatchStatus;

class PatchResult
{
    /**
     * @param  list<string>  $recoveryPaths
     * @param  list<string>  $createdPaths
     */
    public function __construct(
        public string $patchId,
        public string $label,
        public PatchStatus $statusBefore,
        public PatchStatus $statusAfter,
        public ?string $errorMessage = null,
        public array $recoveryPaths = [],
        public bool $manualRequired = false,
        public array $createdPaths = [],
    ) {}

    public function succeeded(): bool
    {
        return ! $this->failed() && $this->statusAfter === PatchStatus::AlreadyApplied
            && $this->statusBefore !== PatchStatus::AlreadyApplied;
    }

    public function skipped(): bool
    {
        return ! $this->failed() && $this->statusBefore === $this->statusAfter
            && $this->statusAfter !== PatchStatus::AlreadyApplied;
    }

    public function failed(): bool
    {
        return $this->errorMessage !== null;
    }
}
