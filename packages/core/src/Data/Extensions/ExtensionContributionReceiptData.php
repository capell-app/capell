<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

use Capell\Core\Enums\ExtensionContributionType;
use Spatie\LaravelData\Data;

final class ExtensionContributionReceiptData extends Data
{
    public function __construct(
        public readonly string $ownerPackage,
        public readonly string $providerBucket,
        public readonly ExtensionContributionType $type,
        public readonly string $key,
        public readonly string $implementation,
        public readonly string $sourceClass,
        public readonly bool $foundationBuiltIn = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ownerPackage' => $this->ownerPackage,
            'providerBucket' => $this->providerBucket,
            'type' => $this->type->value,
            'key' => $this->key,
            'implementation' => $this->implementation,
            'sourceClass' => $this->sourceClass,
            'foundationBuiltIn' => $this->foundationBuiltIn,
        ];
    }
}
