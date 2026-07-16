<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a permission manifest contribution.
 *
 * The manifest describes the permission surface; this contract lets Capell
 * validate the implementation class before the owning authorization
 * integration consumes it.
 */
interface RegistersExtensionPermission extends ExtensionContribution {}
