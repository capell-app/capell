<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Convenience helpers for models that have a meta payload and may
 * reference a related `type` model with its own meta.
 *
 * @mixin Model
 *
 * @property array<string, mixed>|null $meta
 */
trait HasMetaData
{
    public function getMeta(string $key, mixed $default = null): mixed
    {
        $meta = $this->meta ?? [];

        if (Arr::has($meta, $key)) {
            $value = data_get($meta, $key);

            if (filled($value)) {
                return $value;
            }
        }

        if ($this instanceof Blueprintable) {
            $blueprint = $this->getRelationValue('blueprint');

            if ($blueprint instanceof Blueprint) {
                return $blueprint->getMeta($key, $default);
            }
        }

        return $default;
    }
}
