<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Identifies one persisted model whose mutation invalidates a render entry. */
final readonly class PublicRenderDataCacheDependencyData
{
    public function __construct(
        public string $modelType,
        public int|string $modelId,
    ) {
        throw_if($this->modelType === '' || ! is_a($this->modelType, Model::class, true), InvalidArgumentException::class, 'Public render cache dependencies require an Eloquent model class.');
        throw_if($this->modelId === '', InvalidArgumentException::class, 'Public render cache dependencies require a model identifier.');
    }

    public static function forModel(Model $model): self
    {
        $key = $model->getKey();

        throw_unless(is_int($key) || is_string($key), InvalidArgumentException::class, 'Public render cache dependencies require a scalar model identifier.');

        return new self($model::class, $key);
    }

    public function identity(): string
    {
        return $this->modelType . ':' . (string) $this->modelId;
    }
}
