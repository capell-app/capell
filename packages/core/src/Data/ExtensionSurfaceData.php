<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\ExtensionSurface;
use Override;
use Spatie\LaravelData\Data;

final class ExtensionSurfaceData extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $description,
        public readonly ?ExtensionSurface $surface = null,
    ) {}

    public static function fromSurface(string $surface): self
    {
        $normalizedSurface = strtolower(trim($surface));
        $typedSurface = ExtensionSurface::tryFrom($normalizedSurface);

        if ($typedSurface instanceof ExtensionSurface) {
            return new self(
                value: $typedSurface->value,
                label: $typedSurface->label(),
                description: $typedSurface->description(),
                surface: $typedSurface,
            );
        }

        return new self(
            value: $normalizedSurface,
            label: 'Unknown surface',
            description: 'Surface is declared by the package manifest but is not part of the typed Capell surface taxonomy.',
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,
            'surface' => $this->surface?->value,
        ];
    }
}
