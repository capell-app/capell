<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Patching;

/** Stands in for an application router aliased as `Route` in a routes file. */
final class RootRouteEditorCustomRouter
{
    public static int $calls = 0;

    public static function get(): void
    {
        self::$calls++;
    }
}
