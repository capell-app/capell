<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use BackedEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Closure;

trait HasMorphModelRelations
{
    /**
     * @param  array<int|string, string|Closure|array<int, string>>  $base
     * @return array<int|string, string|Closure|array<int, string>>
     */
    public static function mergeMorphRelationDefinitions(
        array $base,
        string|BackedEnum $modelKey,
        ?Language $language = null,
        bool $normalizeKey = false,
    ): array {
        foreach (CapellCore::getModelRelations($modelKey) as $registered) {
            if ($registered instanceof Closure) {
                $registered = $registered($language);
            }

            $items = is_array($registered) ? $registered : [$registered];

            foreach ($items as $relation) {
                if (is_string($relation)) {
                    if (! in_array($relation, $base, true)) {
                        $base[] = $relation;
                    }

                    continue;
                }

                $base[] = $relation;
            }
        }

        if ($normalizeKey) {
            foreach ($base as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    unset($base[$key]);
                    $base[$value] = $value;
                }
            }
        }

        return $base;
    }
}
