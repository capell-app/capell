<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Closure;

/**
 * Tracks error-page generation currently being performed for each site.
 *
 * The generated error page is persisted through the normal Eloquent model
 * lifecycle, so its events must still be emitted and observed. This scope lets
 * the invalidation observer identify those internal writes without disabling
 * observers or suppressing external changes after generation completes.
 */
final class ErrorPageRegenerationScope
{
    /** @var array<int, int> */
    private array $generationDepth = [];

    public function isGenerating(int $siteId): bool
    {
        return ($this->generationDepth[$siteId] ?? 0) > 0;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function whileGenerating(int $siteId, Closure $callback): mixed
    {
        $this->generationDepth[$siteId] = ($this->generationDepth[$siteId] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            if ($this->generationDepth[$siteId] === 1) {
                unset($this->generationDepth[$siteId]);
            } else {
                $this->generationDepth[$siteId]--;
            }
        }
    }
}
