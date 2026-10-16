<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Frontend\Data\PublicPageRenderData;
use Closure;

/**
 * Render-local data for layout providers, never a Blade component attribute.
 * Resolve the scoped service and read current() inside views or render hooks.
 */
final class PublicRenderDataScope
{
    private ?PublicPageRenderData $current = null;

    /** Returns null outside a response render (including standalone components). */
    public function current(): ?PublicPageRenderData
    {
        return $this->current;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $render
     * @return T
     */
    public function during(?PublicPageRenderData $data, Closure $render): mixed
    {
        $previous = $this->current;
        $this->current = $data;

        try {
            return $render();
        } finally {
            $this->current = $previous;
        }
    }
}
