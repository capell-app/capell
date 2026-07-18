<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class AdvancedData extends Data
{
    public function __construct(
        public bool $roundedImages = false,
        public ?string $mainClass = null,
        public ?string $customCss = null,
        public ?string $metaTags = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        return array_filter([
            'rounded_images' => $this->roundedImages,
            'main_class' => $this->mainClass,
            'custom_css' => $this->customCss,
            'meta_tags' => $this->metaTags,
        ], static fn (bool|string|null $value): bool => $value !== null && $value !== false);
    }
}
