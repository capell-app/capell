<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Illuminate\Contracts\Container\Container;
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

    /**
     * Return the bound resolver, binding one from the environment first when no
     * provider has done so yet. Package providers can be discovered before the
     * Core provider, so they must not depend on its registration order.
     */
    public static function boundTo(Container $container): self
    {
        if ($container->bound(self::class)) {
            return $container->make(self::class);
        }

        $resolver = $container->bound(RuntimeRoleSelectionData::class)
            ? new self($container->make(RuntimeRoleSelectionData::class))
            : self::fromEnvironment();
        $container->instance(RuntimeRoleSelectionData::class, $resolver->selection());
        $container->instance(self::class, $resolver);

        return $resolver;
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
