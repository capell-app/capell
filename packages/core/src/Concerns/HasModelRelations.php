<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Closure;

trait HasModelRelations
{
    /**
     * Holds registered model relations for all models.
     *
     * @var array<string, list<string|Closure|array<int, string>>>
     */
    protected static array $modelRelationsRegistry = [];

    /**
     * Register relations for a model key (enum or string).
     * Accepts a string, array of strings, a Closure returning string|array, or array mixing these.
     *
     * @param  string|list<string|Closure|array<int, string>>|Closure  $relations
     */
    public static function registerModelRelations(string|BackedEnum $key, string|array|Closure $relations): void
    {
        if ($key instanceof BackedEnum) {
            $key = (string) $key->value;
        }

        if (! isset(self::$modelRelationsRegistry[$key])) {
            self::$modelRelationsRegistry[$key] = [];
        }

        // Normalize to array so we can iterate; preserve closures (not executed here)
        $items = is_array($relations) ? $relations : [$relations];

        foreach ($items as $relation) {
            // We keep closures & arrays as-is; uniqueness only applied to plain strings
            if (is_string($relation)) {
                if (! in_array($relation, self::$modelRelationsRegistry[$key], true)) {
                    self::$modelRelationsRegistry[$key][] = $relation;
                }

                continue;
            }

            // Avoid duplicate closures by spl_object_id
            if ($relation instanceof Closure) {
                $already = array_any(self::$modelRelationsRegistry[$key], fn ($existing): bool => $existing instanceof Closure && spl_object_id($existing) === spl_object_id($relation));
                if (! $already) {
                    self::$modelRelationsRegistry[$key][] = $relation;
                }

                continue;
            }

            $nestedRelations = array_values($relation);

            if ($nestedRelations !== []) {
                self::$modelRelationsRegistry[$key][] = $nestedRelations;
            }
        }
    }

    /**
     * @return list<string|Closure|array<int, string>>
     */
    public static function getModelRelations(string|BackedEnum $key): array
    {
        if ($key instanceof BackedEnum) {
            $key = (string) $key->value;
        }

        return self::$modelRelationsRegistry[$key] ?? [];
    }
}
