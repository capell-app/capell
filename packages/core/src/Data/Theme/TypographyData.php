<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class TypographyData extends Data
{
    /**
     * @param  array<int, FontData>  $fonts
     */
    public function __construct(
        public array $fonts = [],
        public ?string $fontFamily = null,
        public ?string $fontHeadingFamily = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        return array_filter([
            'fonts' => array_map(fn (FontData $font): array => $font->toArray(), $this->fonts),
            'font_family' => $this->fontFamily,
            'font_heading_family' => $this->fontHeadingFamily,
        ], static fn (string|array|null $value): bool => $value !== null);
    }
}
