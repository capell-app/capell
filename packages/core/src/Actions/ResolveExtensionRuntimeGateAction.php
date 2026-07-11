<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Models\CapellExtension;
use Carbon\CarbonImmutable;
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

        if (! is_array($signedActivation) || ! $this->hasRequiredActivationShape($extension, $signedActivation)) {
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
                'activation' => $signedActivation,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $signedActivation
     */
    private function hasRequiredActivationShape(CapellExtension $extension, array $signedActivation): bool
    {
        foreach (['activation_id', 'composer_name', 'expires_at', 'instance_id', 'signature', 'signature_algorithm', 'signature_issued_at'] as $key) {
            if (! $this->hasNonEmptyString($signedActivation, $key)) {
                return false;
            }
        }

        if ($signedActivation['composer_name'] !== $extension->composer_name) {
            return false;
        }

        return ! $this->activationExpired((string) $signedActivation['expires_at']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNonEmptyString(array $payload, string $key): bool
    {
        return is_string($payload[$key] ?? null) && $payload[$key] !== '';
    }

    private function activationExpired(string $expiresAt): bool
    {
        try {
            return CarbonImmutable::parse($expiresAt)->isPast();
        } catch (Throwable) {
            return true;
        }
    }
}
