<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionScreenshotData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly string $alt,
        public readonly string $caption,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string) ($data['path'] ?? ''),
            alt: (string) ($data['alt'] ?? ''),
            caption: (string) ($data['caption'] ?? ''),
        );
    }

    /** @return array{path: string, alt: string, caption: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'alt' => $this->alt,
            'caption' => $this->caption,
        ];
    }
}
