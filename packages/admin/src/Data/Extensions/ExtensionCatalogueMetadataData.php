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
        public readonly bool $includedWithCapellAll = false,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromApiResponse(array $payload): self
    {
        $catalogueRole = $payload['catalogue_role'] ?? null;
        $maturity = $payload['maturity'] ?? null;
        $maturityLabel = $payload['maturity_label'] ?? null;
        $includedWithCapellAll = $payload['included_with_capell_all'] ?? null;

        if (
            ! is_string($catalogueRole)
            || ! is_string($maturity)
            || ! is_string($maturityLabel)
            || ! is_bool($includedWithCapellAll)
        ) {
            return new self;
        }

        return new self(
            catalogueRole: $catalogueRole,
            maturity: $maturity,
            maturityLabel: $maturityLabel,
            includedWithCapellAll: $includedWithCapellAll,
        )->withSafeFallbacks();
    }

    /** @return array{catalogueRole: string, maturity: string, maturityLabel: string, includedWithCapellAll: bool} */
    public function toTableRecord(): array
    {
        return [
            'catalogueRole' => $this->catalogueRole,
            'maturity' => $this->maturity,
            'maturityLabel' => $this->maturityLabel,
            'includedWithCapellAll' => $this->includedWithCapellAll,
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
            || ($this->maturity === 'labs' && $this->includedWithCapellAll)
        ) {
            return new self;
        }

        return $this;
    }
}
