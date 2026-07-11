<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Models\CapellExtension;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ResolveExtensionRuntimeGateAction
{
    use AsAction;

    public function handle(CapellExtension $extension): ExtensionRuntimeGateData
    {
        $status = $extension->marketplace_runtime_status;

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

        if (! is_array($installedReceipt) || ! $this->hasRequiredReceiptShape($extension, $installedReceipt)) {
            return false;
        }

        $verifier = app()->bound('capell.marketplace.activation-verifier')
            ? resolve('capell.marketplace.activation-verifier')
            : null;

        if (! is_callable($verifier)) {
            return false;
        }

        try {
            return (bool) app()->call($verifier, [
                'extension' => $extension,
                'activation' => $installedReceipt,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $signedReceipt
     */
    private function hasRequiredReceiptShape(CapellExtension $extension, array $signedReceipt): bool
    {
        foreach (['receipt_id', 'composer_name', 'package_version', 'package_identity', 'instance_id', 'domain', 'issued_at', 'signature'] as $key) {
            if (! $this->hasNonEmptyString($signedReceipt, $key)) {
                return false;
            }
        }

        if (($signedReceipt['receipt_version'] ?? null) !== 1
            || $signedReceipt['composer_name'] !== $extension->composer_name
            || ($signedReceipt['perpetual_installed_runtime'] ?? null) !== true
            || ($signedReceipt['runtime_revoked'] ?? null) !== false) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNonEmptyString(array $payload, string $key): bool
    {
        return is_string($payload[$key] ?? null) && $payload[$key] !== '';
    }
}
