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
    /**
     * When issuer enforcement is provisioned, protected installs require an issuer-signed
     * version 2 receipt at signed_activation.installed_receipt_v2 (installed_receipt only when
     * it is itself version 2) with purpose capell-installed-receipt and a runtime_valid_until
     * no more than 30 days after issued_at, renewed by the heartbeat. Core verifies it against
     * extensions.marketplace_receipt_public_keys and extensions.marketplace_instance_id,
     * independently of this seam. Paid/free classification comes only from the issuer-signed
     * commercial catalogue delivered by the heartbeat or an offline import.
     */
    public function licenceDecision(string $slug, string $action, string $domain): ExtensionLicenceDecisionData;

    /**
     * Whether an installed receipt proves this site may keep running the
     * extension after its licence lapsed. Implementations must return true only
     * for a well-formed, unrevoked, perpetual receipt for this extension whose
     * signature verifies.
     * Legacy extension seam retained for callers; Core lifecycle/runtime gates
     * independently verify issuer-signed version 2 receipts.
     *
     * @param  array<string, mixed>  $installedReceipt
     */
    public function verifyActivation(CapellExtension $extension, array $installedReceipt): bool;
}
