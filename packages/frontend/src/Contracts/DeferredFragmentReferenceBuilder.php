<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Support\Fragments\DeferredFragmentReference;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional seam for lazy fragment interaction targets.
 *
 * Capell Frontend does not bind this contract. A companion package that owns a
 * public fragment endpoint binds an implementation in the container; while the
 * contract is unbound, fragment interaction triggers are omitted from public
 * output and the Admin interaction schema hides the fragment target option.
 *
 * Both sides of the round trip deal only in opaque reference strings: the
 * reference produced here is stored as `fragment_reference` on interaction
 * targets, and the URL built from it is printed into public HTML. Neither may
 * expose model IDs, field paths, class names, or other internals — encode the
 * payload (for example with
 * {@see DeferredFragmentReference}).
 */
interface DeferredFragmentReferenceBuilder
{
    /**
     * Build the opaque public reference string for a fragment asset.
     *
     * @param  array<string, mixed>  $meta
     */
    public function reference(Model $asset, array $meta): string;

    /**
     * Build the public URL that renders the referenced fragment.
     *
     * Return null when the reference cannot be resolved to safe public
     * output; the interaction trigger is then dropped instead of rendering
     * a broken control.
     */
    public function url(string $fragmentReference): ?string;
}
