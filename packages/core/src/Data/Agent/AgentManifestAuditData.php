<?php

declare(strict_types=1);

namespace Capell\Core\Data\Agent;

use Spatie\LaravelData\Data;

final class AgentManifestAuditData extends Data
{
    /** @param list<string> $errors */
    public function __construct(
        public readonly bool $interactive,
        public readonly int $declaredCount,
        public readonly int $validatedCount,
        public readonly ?string $waiverReason,
        public readonly array $errors = [],
    ) {}

    /** A missing declaration or a waiver never earns a positive readiness badge. */
    public function isReady(): bool
    {
        return $this->waiverReason === null
            && $this->validatedCount > 0
            && $this->validatedCount === $this->declaredCount
            && $this->errors === [];
    }

    public function isDeclarationMissing(): bool
    {
        return $this->interactive && $this->declaredCount === 0 && $this->waiverReason === null;
    }
}
