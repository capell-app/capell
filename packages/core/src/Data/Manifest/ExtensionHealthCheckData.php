<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionHealthCheckData extends Data
{
    /**
     * @param  class-string|null  $class
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $class,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            class: is_string($data['class'] ?? null) && class_exists($data['class']) ? $data['class'] : null,
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'class' => $this->class,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
