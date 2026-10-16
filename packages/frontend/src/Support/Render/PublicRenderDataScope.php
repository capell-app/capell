<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Frontend\Data\PublicPageRenderData;
use Closure;

/**
 * Render-local data for layout providers, never a Blade component attribute.
 * Read PublicRenderDataScope::current() inside views or render hooks. It is
 * static rather than a container binding so it adds no installed-runtime
 * surface; during() always restores the previous value, so no state outlives
 * a render, including under long-running workers.
 */
final class PublicRenderDataScope
{
    private static ?PublicPageRenderData $current = null;

    /** Returns null outside a response render (including standalone components). */
    public static function current(): ?PublicPageRenderData
    {
        return self::$current;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $render
     * @return T
     */
    public static function during(?PublicPageRenderData $data, Closure $render): mixed
    {
        $previous = self::$current;
        self::$current = $data;

        try {
            return $render();
        } finally {
            self::$current = $previous;
        }
    }
}
