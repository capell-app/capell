<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

/** A post-install hint a package contributes: a translation variant and its display priority. */
final class ExtensionInstallTipData extends Data
{
    public function __construct(
        public readonly string $variant,
        public readonly int $priority,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            variant: (string) ($data['variant'] ?? ''),
            priority: (int) ($data['priority'] ?? 0),
        );
    }

    /**
     * Build a tip from untrusted catalogue data, or null when the row is malformed. Unlike
     * {@see self::fromArray()} it never casts, so an array or object in a field cannot raise.
     *
     * @param  array<string, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $variant = $data['variant'] ?? null;
        $priority = $data['priority'] ?? 0;

        if (! is_string($variant) || preg_match('/^[a-z][a-z0-9_]*$/D', $variant) !== 1 || ! is_int($priority)) {
            return null;
        }

        return new self(variant: $variant, priority: $priority);
    }

    /** @return array{variant: string, priority: int} */
    #[Override]
    public function toArray(): array
    {
        return [
            'variant' => $this->variant,
            'priority' => $this->priority,
        ];
    }
}
