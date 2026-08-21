<?php

declare(strict_types=1);

namespace Capell\Core\Data\Runtime;

use Capell\Core\Enums\RuntimeRole;

final readonly class RuntimeRoleSelectionData
{
    public function __construct(
        public RuntimeRole $role,
        public string $configuredValue,
        public bool $valid,
    ) {}

    public static function fromConfiguredValue(mixed $configuredValue): self
    {
        $value = is_string($configuredValue) ? strtolower(trim($configuredValue)) : '';
        $role = RuntimeRole::tryFrom($value);

        return new self(
            role: $role ?? RuntimeRole::Combined,
            configuredValue: $value,
            valid: $role instanceof RuntimeRole,
        );
    }
}
