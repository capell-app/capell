<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a route manifest contribution.
 *
 * Route metadata is audited before install and test-harness registration.
 * Implementations must keep package routes within the manifest-declared
 * surface.
 */
interface RegistersExtensionRoute extends ExtensionContribution {}
