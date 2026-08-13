<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Capell\Core\Enums\Install\InstallReadinessOutcome;
use Capell\Core\Enums\Install\InstallReadinessStage;
use Capell\Core\Enums\Install\InstallReadinessStatus;
use Override;
use Spatie\LaravelData\Data;

final class InstallReadinessCheckData extends Data
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $key,
        public readonly InstallReadinessStage $stage,
        public readonly string $category,
        public readonly InstallReadinessStatus $status,
        public readonly bool $blocking,
        public readonly InstallReadinessOutcome $outcome,
        public readonly string $message,
        public readonly ?string $remediation = null,
        public readonly ?string $documentationUrl = null,
        public readonly array $evidence = [],
    ) {}

    public function failed(): bool
    {
        return $this->status === InstallReadinessStatus::Blocked;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'stage' => $this->stage->value,
            'category' => $this->category,
            'status' => $this->status->value,
            'blocking' => $this->blocking,
            'outcome' => $this->outcome->value,
            'message' => $this->message,
            'remediation' => $this->remediation,
            'documentation_url' => $this->documentationUrl,
            'evidence' => $this->evidence,
        ];
    }
}
