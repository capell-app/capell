<?php

declare(strict_types=1);

namespace Capell\Core\Support\Publishing;

final class PublicationDateGuard
{
    private static bool $permitted = false;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function allow(callable $callback): mixed
    {
        $previous = self::$permitted;
        self::$permitted = true;

        try {
            return $callback();
        } finally {
            self::$permitted = $previous;
        }
    }

    public static function permitted(): bool
    {
        return self::$permitted || ! self::enabled();
    }

    public static function enabled(): bool
    {
        return (bool) config('capell.publishing.guard_visibility_dates', true);
    }
}
