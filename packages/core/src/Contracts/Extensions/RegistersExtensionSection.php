<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a content section contribution.
 *
 * Section keys and capabilities are manifest-owned. Capell validates this
 * marker before exposing the contribution to content registries.
 */
interface RegistersExtensionSection extends ExtensionContribution {}
