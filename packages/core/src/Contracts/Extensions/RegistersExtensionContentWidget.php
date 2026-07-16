<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a content-widget manifest contribution.
 *
 * Widget keys and capabilities are declared in the manifest. Capell validates
 * this marker before exposing the contribution to content and rendering
 * registries.
 */
interface RegistersExtensionContentWidget extends ExtensionContribution {}
