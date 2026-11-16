<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * The class declared by a permission manifest contribution.
 *
 * The manifest's `permissions` list is the static surface Marketplace and install review read
 * without loading code; the typed declaration is what admin seeds and grants.
 */
interface RegistersExtensionPermission extends DeclaresPermissions, ExtensionContribution {}
