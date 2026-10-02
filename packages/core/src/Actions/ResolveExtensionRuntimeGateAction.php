<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Models\CapellExtension;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ResolveExtensionRuntimeGateAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ExtensionEntitlements $entitlements) {}

    public function handle(CapellExtension $extension): ExtensionRuntimeGateData
    {
        $status = $extension->marketplace_runtime_status;

        if ($extension->provider_recovery_state !== ExtensionProviderRecoveryStateEnum::Healthy) {
            return ExtensionRuntimeGateData::blocked('provider_quarantined');
        }

        if ($extension->is_paid_marketplace_extension && ! $extension->marketplace_runtime_allowed) {
            return ExtensionRuntimeGateData::blocked('marketplace_runtime_disallowed');
        }

        return match ($status) {
            'active' => ExtensionRuntimeGateData::allowed('active'),
            'expired' => $this->hasTrustedActivationForExtension($extension)
                ? ExtensionRuntimeGateData::allowed('expired_but_previously_valid')
                : ExtensionRuntimeGateData::blocked('expired_without_activation'),
            'unverified', 'domain_mismatch', 'unapproved', 'invalid', 'revoked' => ExtensionRuntimeGateData::blocked($status),
            default => $extension->is_paid_marketplace_extension
                ? ExtensionRuntimeGateData::blocked('missing_marketplace_activation')
                : ExtensionRuntimeGateData::allowed('free_or_local_extension'),
        };
    }

    private function hasTrustedActivationForExtension(CapellExtension $extension): bool
    {
        $signedActivation = $extension->marketplace_signed_activation;

        if (! is_array($signedActivation)) {
            return false;
        }

        $installedReceipt = $signedActivation['installed_receipt'] ?? null;

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
