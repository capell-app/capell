<?php

declare(strict_types=1);

namespace Capell\Core\Support\Renderables;

use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Enums\RenderableTypeEnum;
use InvalidArgumentException;

class RenderableRegistry
{
    /** @var array<string, array<string, RenderableDefinitionData>> */
    private array $definitions = [];

    public function register(RenderableDefinitionData $definition): void
    {
        $key = $this->normalizeKey($definition->key);
        $type = $this->normalizeType($definition->type);

        $this->definitions[$type][$key] = $definition;
    }

    /** @param array<int, RenderableDefinitionData> $definitions */
    public function registerMany(array $definitions): void
    {
        foreach ($definitions as $definition) {
            if (! $definition instanceof RenderableDefinitionData) {
                continue;
            }

            $this->register($definition);
        }
    }

    public function get(RenderableTypeEnum|string $type, string $key): RenderableDefinitionData
    {
        $normalizedType = $this->normalizeType($type);
        $normalizedKey = $this->normalizeKey($key);

        $definition = $this->definitions[$normalizedType][$normalizedKey] ?? null;

        throw_if(
            ! $definition instanceof RenderableDefinitionData,
            InvalidArgumentException::class,
            sprintf('Renderable [%s] of type [%s] is not registered.', $normalizedKey, $normalizedType),
        );

        return $definition;
    }

    /** @return array<string, RenderableDefinitionData> */
    public function allForType(RenderableTypeEnum|string $type): array
    {
        return $this->definitions[$this->normalizeType($type)] ?? [];
    }

    /** @return array<string, array<string, RenderableDefinitionData>> */
    public function all(): array
    {
        return $this->definitions;
    }

    private function normalizeType(RenderableTypeEnum|string $type): string
    {
        if ($type instanceof RenderableTypeEnum) {
            return $type->value;
        }

        $normalizedType = trim($type);

        throw_if($normalizedType === '', InvalidArgumentException::class, 'Renderable type cannot be empty.');

        return $normalizedType;
    }

    private function normalizeKey(string $key): string
    {
        $normalizedKey = trim($key);

        throw_if($normalizedKey === '', InvalidArgumentException::class, 'Renderable key cannot be empty.');

        return $normalizedKey;
    }
}
