<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a setting manifest contribution.
 *
 * Settings ownership and schema metadata remain in the manifest. Capell
 * validates this contract before a settings subsystem resolves the class.
 */
interface RegistersExtensionSetting extends ExtensionContribution {}
