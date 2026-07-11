<?php

declare(strict_types=1);

namespace Capell\Core\Models\Casts;

use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<PageTypeData, PageTypeData|string|null>
 */
class BlueprintSubjectDataCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): PageTypeData
    {
        return CapellCore::getPageType($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value instanceof PageTypeData ? $value->name : $value;
    }
}
