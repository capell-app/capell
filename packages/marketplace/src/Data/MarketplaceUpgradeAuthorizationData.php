<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceUpgradeAuthorizationData extends Data
{
    /**
     * @param  array<string, mixed>|null  $composerAuth
     * @param  array<string, mixed>  $signedActivation
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $composerName,
        public readonly string $versionConstraint,
        public readonly ?string $repositoryUrl,
        public readonly ?array $composerAuth,
        public readonly ?string $expiresAt,
        public readonly array $signedActivation = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return new self(
            composerName: (string) ($data['composer_name'] ?? ''),
            versionConstraint: (string) ($data['version_constraint'] ?? '*'),
            repositoryUrl: isset($data['repository_url']) && $data['repository_url'] !== '' ? (string) $data['repository_url'] : null,
            composerAuth: is_array($data['composer_auth'] ?? null) ? $data['composer_auth'] : null,
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            signedActivation: is_array($data['signed_activation'] ?? null) ? $data['signed_activation'] : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
