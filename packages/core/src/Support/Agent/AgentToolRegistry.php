<?php

declare(strict_types=1);

namespace Capell\Core\Support\Agent;

use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<AgentToolDefinitionData> */
final class AgentToolRegistry extends AbstractKeyedRegistry
{
    public function __construct(private readonly AgentToolDefinitionNormalizer $normalizer = new AgentToolDefinitionNormalizer) {}

    public function register(AgentToolDefinitionData $definition): self
    {
        $normalised = $this->normalizer->normalize($definition, $definition->ownerPackage);
        $existing = $this->getItem($normalised->name);

        if ($existing instanceof AgentToolDefinitionData && $existing->toArray() !== $normalised->toArray()) {
            throw new InvalidArgumentException(sprintf('Agent tool [%s] is already registered with different semantics.', $normalised->name));
        }

        $this->setItem($normalised->name, $normalised);

        return $this;
    }

    public function definition(string $name): AgentToolDefinitionData
    {
        return $this->getItem($name)
            ?? throw new InvalidArgumentException(sprintf('Agent tool [%s] is not registered.', $name));
    }

    public function has(string $name): bool
    {
        return $this->hasItem($name);
    }

    /** @return array<string, AgentToolDefinitionData> */
    public function definitions(): array
    {
        $definitions = $this->allItems();
        ksort($definitions);

        return $definitions;
    }
}
