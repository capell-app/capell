<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Spatie\LaravelData\Data;

/**
 * What a remediation did, in enough detail to audit and, where possible, undo.
 */
final class SecurityRemediationOutcomeData extends Data
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly bool $applied,
        public readonly string $message,
        public readonly array $before = [],
        public readonly array $after = [],
    ) {}

    public static function skipped(string $message): self
    {
        return new self(applied: false, message: $message);
    }
}
