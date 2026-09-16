<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionCatalogueMetadataData extends Data
{
    public function __construct(
        public readonly string $catalogueRole = 'extension',
        public readonly string $maturity = 'labs',
        public readonly string $maturityLabel = 'Labs',
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromApiResponse(array $payload): self
    {
        $catalogueRole = $payload['catalogue_role'] ?? null;
        $maturity = $payload['maturity'] ?? null;
        $maturityLabel = $payload['maturity_label'] ?? null;
        if (
            ! is_string($catalogueRole)
            || ! is_string($maturity)
            || ! is_string($maturityLabel)
        ) {
            return new self;
        }

        return new self(
            catalogueRole: $catalogueRole,
            maturity: $maturity,
            maturityLabel: $maturityLabel,
        )->withSafeFallbacks();
    }

    /** @return array{catalogueRole: string, maturity: string, maturityLabel: string} */
    public function toTableRecord(): array
    {
        return [
            'catalogueRole' => $this->catalogueRole,
            'maturity' => $this->maturity,
            'maturityLabel' => $this->maturityLabel,
        ];
    }

    public function withSafeFallbacks(): self
    {
        $expectedMaturityLabel = match ($this->maturity) {
            'stable' => 'Released',
            'beta' => 'Beta',
            'labs' => 'Labs',
            default => null,
        };

        if (
            ! in_array($this->catalogueRole, ['core', 'extension'], true)
            || $this->maturityLabel !== $expectedMaturityLabel
        ) {
            return new self;
        }

        return $this;
    }
}
