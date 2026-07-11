<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionDependencyData extends Data
{
    /**
     * @param  list<string>  $requires
     * @param  list<string>  $supports
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public readonly array $requires = [],
        public readonly array $supports = [],
        public readonly array $conflicts = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            requires: self::stringList($data['requires'] ?? []),
            supports: self::stringList($data['supports'] ?? []),
            conflicts: self::stringList($data['conflicts'] ?? []),
        );
    }

    /** @return array{requires: list<string>, supports: list<string>, conflicts: list<string>} */
    #[Override]
    public function toArray(): array
    {
        return [
            'requires' => $this->requires,
            'supports' => $this->supports,
            'conflicts' => $this->conflicts,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
