<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\VerifyMarketplaceSignedActivationAction;
use Capell\Marketplace\Services\MarketplaceClient;
use Override;

/**
 * Licence decisions go through the signed client because the marketplace
 * rejects unsigned licence calls; installed receipts are checked against the
 * receipt format the marketplace issues before their signature is verified.
 */
final readonly class MarketplaceExtensionEntitlements implements ExtensionEntitlements
{
    private const array REQUIRED_RECEIPT_STRINGS = [
        'receipt_id',
        'composer_name',
        'package_version',
        'package_identity',
        'instance_id',
        'domain',
        'issued_at',
        'signature',
    ];

    public function __construct(private MarketplaceClient $client) {}

    #[Override]
    public function licenceDecision(string $slug, string $action, string $domain): ExtensionLicenceDecisionData
    {
        return $this->client->extensionLicenceDecision($slug, $action, $domain);
    }

    /**
     * @param  array<string, mixed>  $installedReceipt
     */
    #[Override]
    public function verifyActivation(CapellExtension $extension, array $installedReceipt): bool
    {
        if (! $this->hasRequiredReceiptShape($extension, $installedReceipt)) {
            return false;
        }

        return VerifyMarketplaceSignedActivationAction::run($extension, $installedReceipt);
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function hasRequiredReceiptShape(CapellExtension $extension, array $receipt): bool
    {
        foreach (self::REQUIRED_RECEIPT_STRINGS as $key) {
            if (! is_string($receipt[$key] ?? null) || $receipt[$key] === '') {
                return false;
            }
        }

        return ($receipt['receipt_version'] ?? null) === 1
            && $receipt['composer_name'] === $extension->composer_name
            && ($receipt['perpetual_installed_runtime'] ?? null) === true
            && ($receipt['runtime_revoked'] ?? null) === false;
    }
}
