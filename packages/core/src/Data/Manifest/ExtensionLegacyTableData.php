<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

/** A table a package took over from an earlier owner: its name, the config key that sets it and the migration that creates it. */
final class ExtensionLegacyTableData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $configKey,
        public readonly string $migration,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            configKey: (string) ($data['configKey'] ?? ''),
            migration: (string) ($data['migration'] ?? ''),
        );
    }

    /** @return array{name: string, configKey: string, migration: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'configKey' => $this->configKey,
            'migration' => $this->migration,
        ];
    }
}
