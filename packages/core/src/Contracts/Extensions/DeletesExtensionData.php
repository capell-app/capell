<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Data\PackageData;

/**
 * Allows an extension provider to remove extension-owned data on uninstall.
 *
 * Capell resolves implementing provider classes during the explicit
 * delete-data lifecycle. Implementations must be safe to call once for the
 * supplied package and must not remove host- or customer-owned data.
 */
interface DeletesExtensionData extends ExtensionContribution
{
    /** Delete only data owned by the supplied extension package. */
    public function deleteExtensionData(PackageData $package): void;
}
