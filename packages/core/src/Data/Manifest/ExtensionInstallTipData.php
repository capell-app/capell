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
