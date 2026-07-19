<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallState;
use Spatie\LaravelData\Data;

final class MarketplaceInstallEligibilityData extends Data
{
    private const KNOWN_BLOCK_REASONS = [
        'activation_required',
        'blocked',
        'checkout_unavailable',
        'email_verification_required',
        'entitlement_required',
        'incompatible',
        'marketplace_unavailable',
        'missing_policy',
        'not_connected',
        'account_required',
        'capell_all_required',
        'purchase_required',
    ];

    public function __construct(
        public readonly ?MarketplaceInstallState $state,
        public readonly ?string $blockReason = null,
        public readonly bool $fallbackAllowed = false,
        public readonly bool $missingPolicy = false,
        public readonly bool $canInstall = false,
        public readonly bool $canUpdate = false,
        public readonly bool $canRunExisting = false,
        /** @var array<string, mixed> */
        public readonly array $domainVerification = [],
        /** @var array<string, mixed> */
        public readonly array $requirements = [],
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {}

    public static function fromPayload(mixed $payload, bool $protectedInstall = false): self
    {
        if ($payload === null || $payload === '') {
            return $protectedInstall
                ? self::missingProtectedPolicy()
                : new self(state: null);
        }

        if (is_string($payload)) {
            $state = self::stateFromString($payload);

            return new self(
                state: $state,
                blockReason: self::blockReasonFromPayload($payload, $state),
                metadata: ['raw' => $payload],
            );
        }

        if (! is_array($payload)) {
            return $protectedInstall
                ? self::missingProtectedPolicy()
                : new self(state: null);
        }

        $state = self::stateFromPayload($payload);
        $allowed = self::booleanValue($payload['allowed'] ?? $payload['can_install'] ?? null);
        $canInstall = self::booleanValue($payload['can_install'] ?? $payload['allowed'] ?? null);
        $canUpdate = self::booleanValue($payload['can_update'] ?? null);
        $canRunExisting = self::booleanValue($payload['can_run_existing'] ?? null);
        $blockReason = self::normalizeBlockReason(self::stringValue(
            $payload['block_reason']
                ?? $payload['blocked_reason']
                ?? $payload['blockReason']
                ?? $payload['reason']
                ?? null,
        ));

        if (! $state instanceof MarketplaceInstallState && $allowed === true) {
            $state = MarketplaceInstallState::Authorized;
        }

        if (! $state instanceof MarketplaceInstallState && $allowed === false) {
            $state = MarketplaceInstallState::Blocked;
        }

        return new self(
            state: $state,
            blockReason: $blockReason,
            fallbackAllowed: self::booleanValue($payload['fallback_allowed'] ?? $payload['allowed_fallback'] ?? $payload['fallbackAllowed'] ?? null) ?? false,
            missingPolicy: self::booleanValue($payload['missing_policy'] ?? $payload['missingPolicy'] ?? null) ?? false,
            canInstall: $canInstall ?? ($state === MarketplaceInstallState::Authorized),
            canUpdate: $canUpdate ?? $canInstall ?? ($state === MarketplaceInstallState::Authorized),
            canRunExisting: $canRunExisting ?? ($state === MarketplaceInstallState::Authorized),
            domainVerification: is_array($payload['domain_verification'] ?? null) ? $payload['domain_verification'] : [],
            requirements: is_array($payload['requirements'] ?? null) ? $payload['requirements'] : [],
            metadata: $payload,
        );
    }

    public function blocksInstall(): bool
    {
        return $this->state === MarketplaceInstallState::Blocked
            || $this->state === MarketplaceInstallState::Incompatible
            || $this->missingPolicy;
    }

    public function decision(): string
    {
        if ($this->missingPolicy) {
            return 'missing_policy';
        }

        return $this->state?->value ?? 'unspecified';
    }

    private static function missingProtectedPolicy(): self
    {
        return new self(
            state: MarketplaceInstallState::Blocked,
            blockReason: 'missing_policy',
            missingPolicy: true,
        );
    }

    private static function blockReasonFromPayload(string $payload, ?MarketplaceInstallState $state): ?string
    {
        if ($state === MarketplaceInstallState::Blocked) {
            return self::normalizeBlockReason($payload);
        }

        if (! $state instanceof MarketplaceInstallState) {
            return self::normalizeBlockReason($payload);
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private static function stateFromPayload(array $payload): ?MarketplaceInstallState
    {
        $state = self::stringValue(
            $payload['state']
                ?? $payload['install_state']
                ?? $payload['status']
                ?? $payload['decision']
                ?? null,
        );

        return $state === null ? null : self::stateFromString($state);
    }

    private static function stateFromString(string $state): ?MarketplaceInstallState
    {
        return MarketplaceInstallState::tryFrom($state)
            ?? match ($state) {
                'allowed', 'eligible', 'ready', 'account_linked_allowed' => MarketplaceInstallState::Authorized,
                'needs_purchase', 'purchase' => MarketplaceInstallState::PurchaseRequired,
                'needs_activation', 'activation' => MarketplaceInstallState::ActivationRequired,
                'blocked', 'missing_policy', 'public_verification_required', 'domain_verification_required',
                'account_required', 'capell_all_required', 'email_verification_required', 'entitlement_required',
                'verification_required' => MarketplaceInstallState::Blocked,
                default => null,
            };
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue !== '' ? $stringValue : null;
    }

    private static function booleanValue(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function normalizeBlockReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        if (in_array($reason, ['domain_verification_required', 'public_verification_required', 'verification_required'], true)) {
            return 'entitlement_required';
        }

        return in_array($reason, self::KNOWN_BLOCK_REASONS, true) ? $reason : 'blocked';
    }
}
