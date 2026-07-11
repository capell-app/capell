<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

final class LocalizedMediaMetadata
{
    public function __construct(
        public ?int $languageId,
        public ?string $title,
        public ?string $alt,
        public ?string $caption,
        public ?string $credit,
        public bool $decorative,
    ) {}

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function fromTranslation(?int $languageId, ?string $title, ?array $meta): self
    {
        $meta ??= [];

        return new self(
            languageId: $languageId,
            title: $title,
            alt: self::stringOrNull($meta['alt'] ?? null),
            caption: self::stringOrNull($meta['caption'] ?? null),
            credit: self::stringOrNull($meta['credit'] ?? null),
            decorative: (bool) ($meta['decorative'] ?? false),
        );
    }

    /**
     * @return array{language_id: int|null, title: string|null, alt: string|null, caption: string|null, credit: string|null, decorative: bool}
     */
    public function toArray(): array
    {
        return [
            'language_id' => $this->languageId,
            'title' => $this->title,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'credit' => $this->credit,
            'decorative' => $this->decorative,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $value !== '' ? $value : null;
    }
}
