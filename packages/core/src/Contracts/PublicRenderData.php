<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

/**
 * Marks a Data class as part of the public render boundary: what public Blade,
 * JSON-LD, meta, fragments and exports may read.
 *
 * Implementations hold hydrated scalars, enums and other Data only. They never
 * hold an Eloquent model or a Pageable, because a model reaches the view with
 * lazy relations, authoring attributes and query methods attached. The
 * `capell.publicRenderDataModel` PHPStan rule enforces this on every class that
 * implements the marker.
 */
interface PublicRenderData {}
