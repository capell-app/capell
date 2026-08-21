<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Illuminate\Support\Env;

final readonly class RuntimeRoleResolver
{
    public function __construct(private RuntimeRoleSelectionData $selection) {}

    public static function fromEnvironment(): self
    {
        return new self(RuntimeRoleSelectionData::fromConfiguredValue(
            Env::get('CAPELL_RUNTIME_ROLE', RuntimeRole::Combined->value),
        ));
    }

    public function role(): RuntimeRole
    {
        return $this->selection->role;
    }

    public function selection(): RuntimeRoleSelectionData
    {
        return $this->selection;
    }
}
