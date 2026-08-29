<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Capell\Core\Enums\ExtensionContributionType;
use Override;
use Spatie\LaravelData\Data;

final class ExtensionContributionTraceabilityEntryData extends Data
{
    public function __construct(
        public readonly ExtensionContributionType $type,
        public readonly string $key,
        public readonly ?string $class = null,
        public readonly string $providerBucket = 'runtime',
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: ExtensionContributionType::from((string) $data['type']),
            key: (string) ($data['key'] ?? $data['name'] ?? ''),
            class: is_string($data['class'] ?? null) ? $data['class'] : null,
            providerBucket: (string) ($data['providerBucket'] ?? $data['bucket'] ?? 'runtime'),
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'key' => $this->key,
            'class' => $this->class,
            'providerBucket' => $this->providerBucket,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
