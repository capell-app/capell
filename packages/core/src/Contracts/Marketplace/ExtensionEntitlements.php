<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Marketplace;

use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Models\CapellExtension;

/**
 * Core's single seam onto marketplace licensing.
 *
 * Core ships a default that works without the marketplace package; the
 * marketplace package replaces it with an implementation that signs every
 * request, because the marketplace rejects unsigned licence calls.
 */
interface ExtensionEntitlements
{
    public function licenceDecision(string $slug, string $action, string $domain): ExtensionLicenceDecisionData;

    /**
     * Whether an installed receipt proves this site may keep running the
     * extension after its licence lapsed. Implementations must return true only
     * for a well-formed, unrevoked, perpetual receipt for this extension whose
     * signature verifies.
     *
     * @param  array<string, mixed>  $installedReceipt
     */
    public function verifyActivation(CapellExtension $extension, array $installedReceipt): bool;
}
