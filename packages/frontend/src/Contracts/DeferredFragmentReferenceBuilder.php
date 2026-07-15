<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional compatibility seam for lazy fragment interaction targets.
 *
 * Capell Frontend does not bind this contract. A companion package that owns a
 * public fragment endpoint may bind an implementation while it migrates to the
 * owner-based public fragment resolver API.
 *
 * Both sides of the round trip deal only in opaque reference strings. Neither
 * may expose model IDs, field paths, class names, or other internals.
 */
interface DeferredFragmentReferenceBuilder
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function reference(Model $asset, array $meta): string;

    public function url(string $fragmentReference): ?string;
}
