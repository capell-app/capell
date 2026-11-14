<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Marketplace\VerifyExtensionActivationAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ResolveExtensionRuntimeGateAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ExtensionCommercialPolicy $policy,
        private readonly ExtensionEntitlements $entitlements,
    ) {}

    public function handle(CapellExtension $extension): ExtensionRuntimeGateData
    {
        $status = $extension->marketplace_runtime_status;
        $protected = $this->policy->requiresAuthorisation($extension->composer_name, $extension);

        if ($extension->provider_recovery_state !== ExtensionProviderRecoveryStateEnum::Healthy) {
            return ExtensionRuntimeGateData::blocked('provider_quarantined');
        }

        if (($extension->is_paid_marketplace_extension || ($protected && $status !== null)) && ! $extension->marketplace_runtime_allowed) {
            return ExtensionRuntimeGateData::blocked('marketplace_runtime_disallowed');
        }

        if (in_array($status, ['unverified', 'domain_mismatch', 'unapproved', 'invalid', 'revoked'], true)) {
            return ExtensionRuntimeGateData::blocked($status);
        }

        if (! $this->policy->enforcing()) {
            return $this->legacyGate($extension, $status);
        }

        if ($protected && $this->policy->isLocalDevelopment($extension->composer_name)) {
            return ExtensionRuntimeGateData::allowed('explicit_local_development');
        }

        return match ($status) {
            'active' => ! $protected || ($extension->marketplace_runtime_allowed && VerifyExtensionActivationAction::run($extension))
                ? ExtensionRuntimeGateData::allowed('active')
                : ExtensionRuntimeGateData::blocked('active_without_activation'),
            'expired' => VerifyExtensionActivationAction::run($extension)
                ? ExtensionRuntimeGateData::allowed('expired_but_previously_valid')
                : ExtensionRuntimeGateData::blocked('expired_without_activation'),
            default => $protected
                ? ExtensionRuntimeGateData::blocked('missing_marketplace_activation')
                : ExtensionRuntimeGateData::allowed('free_or_local_extension'),
        };
    }

    /** Behaviour before the issuer key is provisioned: paid flags and the entitlement seam. */
    private function legacyGate(CapellExtension $extension, ?string $status): ExtensionRuntimeGateData
    {
        return match ($status) {
            'active' => ExtensionRuntimeGateData::allowed('active'),
            'expired' => $this->legacyActivationVerified($extension)
                ? ExtensionRuntimeGateData::allowed('expired_but_previously_valid')
                : ExtensionRuntimeGateData::blocked('expired_without_activation'),
            default => $extension->is_paid_marketplace_extension
                ? ExtensionRuntimeGateData::blocked('missing_marketplace_activation')
                : ExtensionRuntimeGateData::allowed('free_or_local_extension'),
        };
    }

    private function legacyActivationVerified(CapellExtension $extension): bool
    {
        $installedReceipt = is_array($extension->marketplace_signed_activation)
            ? ($extension->marketplace_signed_activation['installed_receipt'] ?? null)
            : null;

        if (! is_array($installedReceipt)) {
            return false;
        }

        try {
            /** @var array<string, mixed> $installedReceipt */
            return $this->entitlements->verifyActivation($extension, $installedReceipt);
        } catch (Throwable) {
            return false;
        }
    }
}
