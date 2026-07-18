<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Renderables;

use Capell\Core\Enums\RenderableTypeEnum;
use Illuminate\Database\Eloquent\Model;

final class RenderableDynamicDataRegistry
{
    /** @var array<string, list<callable(Model, Model, array<string, mixed>, string): array<string, mixed>>> */
    private array $contributors = [];

    public function register(RenderableTypeEnum|string $type, string $key, callable $contributor): void
    {
        $this->contributors[$this->registryKey($type, $key)][] = $contributor;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function data(RenderableTypeEnum|string $type, string $key, Model $asset, Model $translation, array $meta): array
    {
        $data = [];

        foreach ($this->contributorsFor($type, $key) as $contributor) {
            $data = array_replace_recursive($data, $contributor($asset, $translation, $meta, $key));
        }

        return $data;
    }

    /**
     * @return list<callable(Model, Model, array<string, mixed>, string): array<string, mixed>>
     */
    private function contributorsFor(RenderableTypeEnum|string $type, string $key): array
    {
        return [
            ...($this->contributors[$this->registryKey($type, '*')] ?? []),
            ...($this->contributors[$this->registryKey($type, $key)] ?? []),
        ];
    }

    private function registryKey(RenderableTypeEnum|string $type, string $key): string
    {
        $type = $type instanceof RenderableTypeEnum ? $type->value : $type;

        return trim($type) . ':' . trim($key);
    }
}
