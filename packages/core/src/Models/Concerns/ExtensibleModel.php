<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

trait ExtensibleModel
{
    /**
     * @var array<string, array<int, string>>
     */
    protected static array $extraFillable = [];

    /**
     * @var array<string, array<string, string>>
     */
    protected static array $extraCasts = [];

    /**
     * @param  list<string>  $fields
     */
    public static function addFillable(array $fields): void
    {
        $class = static::class;
        if (! isset(self::$extraFillable[$class])) {
            self::$extraFillable[$class] = [];
        }

        foreach ($fields as $field) {
            if (! in_array($field, self::$extraFillable[$class], true)) {
                self::$extraFillable[$class][] = $field;
            }
        }
    }

    /**
     * @param  array<string, string>  $casts
     */
    public static function addCasts(array $casts): void
    {
        $class = static::class;
        if (! isset(self::$extraCasts[$class])) {
            self::$extraCasts[$class] = [];
        }

        self::$extraCasts[$class] = array_merge(self::$extraCasts[$class], $casts);
    }

    public function getFillable(): array
    {
        $base = $this->fillable;
        $class = static::class;
        $extra = self::$extraFillable[$class] ?? [];

        return array_values(array_unique(array_merge($base, $extra)));
    }

    /**
     * Merge the model's casts() with dynamically registered casts.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        $base = $this->casts();
        $class = static::class;
        $extra = self::$extraCasts[$class] ?? [];

        return array_merge($base, $extra);
    }
}
