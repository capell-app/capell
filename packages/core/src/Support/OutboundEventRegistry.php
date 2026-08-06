<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Data\OutboundEventDefinitionData;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class OutboundEventRegistry
{
    /** @var array<string, OutboundEventDefinitionData> */
    private array $definitions = [];

    private bool $frozen = false;

    public function register(OutboundEventDefinitionData $definition): self
    {
        if ($this->frozen) {
            throw new InvalidArgumentException('Outbound event registration is frozen.');
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/', $definition->name)) {
            throw new InvalidArgumentException(sprintf(
                'Outbound event name [%s] must use the vendor-package.event-name format.',
                $definition->name,
            ));
        }

        if ($definition->version < 1) {
            throw new InvalidArgumentException('Outbound event versions must be positive integers.');
        }

        if ($definition->description === '' || $definition->ownerPackage === '') {
            throw new InvalidArgumentException('Outbound event descriptions and owner packages cannot be empty.');
        }

        if (! is_a($definition->payloadClass, Data::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Outbound event payload [%s] must extend [%s].',
                $definition->payloadClass,
                Data::class,
            ));
        }

        if (isset($this->definitions[$definition->name])) {
            throw new InvalidArgumentException(sprintf(
                'Outbound event [%s] is already registered.',
                $definition->name,
            ));
        }

        $this->definitions[$definition->name] = $definition;

        return $this;
    }

    public function definition(string $name): OutboundEventDefinitionData
    {
        return $this->definitions[$name]
            ?? throw new InvalidArgumentException(sprintf('Outbound event [%s] is not registered.', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /** @return array<string, OutboundEventDefinitionData> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
