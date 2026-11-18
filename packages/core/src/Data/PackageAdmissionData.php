<?php

declare(strict_types=1);

namespace Capell\Core\Data;

/**
 * The outcome of running work under package admission. When the package was not
 * admitted the work did not run, and the reason says which durable state refused it.
 */
final readonly class PackageAdmissionData
{
    private function __construct(
        public bool $admitted,
        public string $reason,
        public mixed $value = null,
    ) {}

    public static function admitted(mixed $value): self
    {
        return new self(admitted: true, reason: 'admitted', value: $value);
    }

    public static function refused(string $reason): self
    {
        return new self(admitted: false, reason: $reason);
    }
}
