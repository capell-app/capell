<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class AdminData extends Data
{
    public function __construct(
        public ?string $icon = null,
        public ?string $image = null,
        public ?string $description = null,
        public ?string $generatedImage = null,
        public ?string $generatedImageSignature = null,
        public ?string $generatedImageStatus = null,
        public ?string $generatedImageError = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        return array_filter([
            'icon' => $this->icon,
            'image' => $this->image,
            'description' => $this->description,
            'generated_image' => $this->generatedImage,
            'generated_image_signature' => $this->generatedImageSignature,
            'generated_image_status' => $this->generatedImageStatus,
            'generated_image_error' => $this->generatedImageError,
        ], static fn (?string $value): bool => $value !== null);
    }
}
