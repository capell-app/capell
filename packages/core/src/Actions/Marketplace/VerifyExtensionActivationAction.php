<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Models\CapellExtension;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Verifies the issuer-signed version 2 installed receipt held for an extension. Receipts are
 * short-lived and renewed by the heartbeat, so revocation and licence moves take effect once the
 * current receipt's runtime window ends.
 */
final class VerifyExtensionActivationAction
{
    use AsObject;

    public const string PURPOSE = 'capell-installed-receipt';

    /** The longest runtime window Core honours, whatever the receipt claims. */
    public const int MAX_VALIDITY_DAYS = 30;

    private const int CLOCK_SKEW_SECONDS = 300;

    /**
     * The v2 receipt lives at installed_receipt_v2; installed_receipt is accepted only when it is
     * itself version 2, because released Marketplace packages still read version 1 there.
     *
     * @return array<string, mixed>|null
     */
    public static function receiptFrom(mixed $activation): ?array
    {
        if (! is_array($activation)) {
            return null;
        }

        $receipt = $activation['installed_receipt_v2'] ?? null;
        if (! is_array($receipt)) {
            $legacy = $activation['installed_receipt'] ?? null;
            $receipt = is_array($legacy) && ($legacy['receipt_version'] ?? null) === 2 ? $legacy : null;
        }

        /** @var array<string, mixed>|null $receipt */
        return $receipt;
    }

    public function handle(CapellExtension $extension): bool
    {
        $receipt = self::receiptFrom($extension->marketplace_signed_activation);
        if ($receipt === null) {
            return false;
        }

        $revokedAfter = is_array($extension->marketplace_signed_activation)
            ? ($extension->marketplace_signed_activation['revoked_after'] ?? null)
            : null;

        return $this->verify($receipt, $extension->composer_name, is_string($revokedAfter) ? $revokedAfter : null);
    }

    /** @param array<string, mixed> $receipt */
    public function verify(array $receipt, string $composerName, ?string $revokedAfter = null): bool
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (($receipt['receipt_version'] ?? null) !== 2
            || ($receipt['purpose'] ?? null) !== self::PURPOSE
            || ! is_string($domain) || $domain === ''
            || ! is_string($receipt['composer_name'] ?? null)
            || strtolower($receipt['composer_name']) !== strtolower($composerName)
            || ! is_string($receipt['domain'] ?? null)
            || strtolower(rtrim($receipt['domain'], '.')) !== strtolower(rtrim($domain, '.'))
            || ($receipt['perpetual_installed_runtime'] ?? null) !== true
            || ($receipt['runtime_revoked'] ?? null) !== false) {
            return false;
        }

        foreach (['receipt_id', 'package_version', 'package_identity', 'instance_id', 'issued_at', 'runtime_valid_until'] as $field) {
            if (! is_string($receipt[$field] ?? null) || $receipt[$field] === '') {
                return false;
            }
        }

        $instanceId = config('capell.extensions.marketplace_instance_id');
        if (! is_string($instanceId) || $instanceId === '' || $receipt['instance_id'] !== $instanceId) {
            return false;
        }

        if (! $this->withinRuntimeWindow($receipt['issued_at'], $receipt['runtime_valid_until'], $revokedAfter)) {
            return false;
        }

        return VerifyMarketplaceIssuerSignatureAction::run($receipt);
    }

    private function withinRuntimeWindow(string $issuedAt, string $validUntil, ?string $revokedAfter): bool
    {
        try {
            $issued = CarbonImmutable::parse($issuedAt);
            $until = CarbonImmutable::parse($validUntil);
            $revoked = $revokedAfter !== null ? CarbonImmutable::parse($revokedAfter) : null;
        } catch (Throwable) {
            return false;
        }

        $now = CarbonImmutable::now();

        return $issued->lessThanOrEqualTo($now->addSeconds(self::CLOCK_SKEW_SECONDS))
            && $until->greaterThan($issued)
            && $until->lessThanOrEqualTo($issued->addDays(self::MAX_VALIDITY_DAYS))
            && $now->lessThan($until)
            // A revocation applied on this runtime holds until the issuer signs a later receipt.
            && (! $revoked instanceof CarbonImmutable || $issued->greaterThan($revoked));
    }
}
