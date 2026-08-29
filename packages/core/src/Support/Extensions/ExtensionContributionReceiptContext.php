<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

final readonly class ExtensionContributionReceiptContext
{
    public function __construct(
        public string $ownerPackage,
        public string $providerBucket,
        public string $sourceClass,
        public bool $foundationBuiltIn = false,
    ) {}

    public static function forPackage(string $package, string $bucket, string $sourceClass): self
    {
        return new self($package, $bucket, $sourceClass);
    }

    public static function foundation(string $package, string $bucket, string $sourceClass): self
    {
        return new self($package, $bucket, $sourceClass, true);
    }
}
