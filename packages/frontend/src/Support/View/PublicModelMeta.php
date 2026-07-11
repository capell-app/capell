<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\View;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class PublicModelMeta
{
    public static function get(?object $model, string $key, mixed $default = null): mixed
    {
        if ($model === null) {
            return $default;
        }

        $meta = $model instanceof Model
            ? $model->getAttribute('meta')
            : ($model->meta ?? []);

        if (! is_array($meta)) {
            $meta = [];
        }

        if (Arr::has($meta, $key)) {
            $value = data_get($meta, $key);

            if (in_array($value, [false, 0, '0'], true) || filled($value)) {
                return $value;
            }
        }

        if ($model instanceof Blueprintable && $model instanceof Model && $model->relationLoaded('type')) {
            $type = $model->getRelation('type');

            if ($type instanceof Blueprint) {
                return self::get($type, $key, $default);
            }
        }

        return $default;
    }
}
