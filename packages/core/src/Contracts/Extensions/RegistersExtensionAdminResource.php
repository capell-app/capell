<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by an admin-resource manifest contribution.
 *
 * Registration metadata remains in the manifest; this marker lets Capell
 * reject a mismatched contribution class before Admin consumes it.
 */
interface RegistersExtensionAdminResource extends ExtensionContribution {}
