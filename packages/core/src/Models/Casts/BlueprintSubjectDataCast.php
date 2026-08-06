<?php

declare(strict_types=1);

namespace Capell\Core\Models\Casts;

use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<PageTypeData, PageTypeData|string|null>
 */
class BlueprintSubjectDataCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): PageTypeData
    {
        if ($value === 'navigation') {
            return CapellCore::getPageType($value);
        }

        $subject = resolve(BlueprintSubjectRegistry::class)->descriptor((string) $value);

        return new PageTypeData(
            name: $subject->key,
            model: $subject->modelClass,
            label: $subject->label,
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value instanceof PageTypeData) {
            return $value->name;
        }

        if ($value instanceof BlueprintSubjectEnum) {
            return $value->getKey();
        }

        if (! is_string($value)) {
            return $value;
        }

        // Navigation is an internal blueprint record, not a blueprint subject.
        if ($value === 'navigation') {
            return $value;
        }

        return resolve(BlueprintSubjectRegistry::class)->descriptor($value)->key;
    }
}
