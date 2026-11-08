<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Support;

use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Closure;

/**
 * The provenance applied to page versions recorded inside a callback. Import
 * and package publish flows wrap their saves in {@see self::run()} so the
 * versions they create are labelled with where they came from. Outside a run,
 * versions are ordinary edits.
 */
final class PageVersionContext
{
    private ?PageVersionSource $source = null;

    private ?string $label = null;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(PageVersionSource $source, ?string $label, Closure $callback): mixed
    {
        $previousSource = $this->source;
        $previousLabel = $this->label;

        $this->source = $source;
        $this->label = $label;

        try {
            return $callback();
        } finally {
            $this->source = $previousSource;
            $this->label = $previousLabel;
        }
    }

    public function source(): ?PageVersionSource
    {
        return $this->source;
    }

    public function label(): ?string
    {
        return $this->label;
    }
}
