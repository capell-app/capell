<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Capell\Core\Enums\ExtensionContributionType;
use Override;
use Spatie\LaravelData\Data;

final class ExtensionContributionData extends Data
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ExtensionContributionType $type,
        public readonly ?string $class = null,
        public readonly array $metadata = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = ExtensionContributionType::from((string) $data['type']);
        $class = is_string($data['class'] ?? null) && $data['class'] !== '' ? $data['class'] : null;
        unset($data['type'], $data['class']);

        return new self(
            type: $type,
            class: $class,
            metadata: $data,
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'class' => $this->class,
            ...$this->metadata,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
