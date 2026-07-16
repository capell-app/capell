<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a page-type or page-variation contribution.
 *
 * Type keys and schema metadata are manifest-owned. Capell validates this
 * marker before the type contribution is admitted to the package registry.
 */
interface RegistersExtensionPageType extends ExtensionContribution {}
