<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallSource;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class MarketplaceInstallRequestData extends Data
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $extensionSlug,
        public readonly array $options,
        public readonly MarketplaceInstallActorData $actor,
        public readonly bool $betaAcknowledged,
        public readonly MarketplaceInstallSource $source,
    ) {
        if (trim($extensionSlug) === '') {
            throw new InvalidArgumentException('A Marketplace extension slug is required.');
        }
    }

    /** @param array<string, mixed> $options */
    public static function make(
        string $extensionSlug,
        array $options,
        MarketplaceInstallActorData $actor,
        bool $betaAcknowledged,
        MarketplaceInstallSource $source,
    ): self {
        ksort($options);

        return new self(
            extensionSlug: mb_strtolower(trim($extensionSlug)),
            options: $options,
            actor: $actor,
            betaAcknowledged: $betaAcknowledged,
            source: $source,
        );
    }
}
