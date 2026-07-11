<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class ExtensionRuntimeGateData extends Data
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {}

    public static function allowed(string $reason): self
    {
        return new self(
            allowed: true,
            reason: $reason,
        );
    }

    public static function blocked(string $reason): self
    {
        return new self(
            allowed: false,
            reason: $reason,
        );
    }
}
