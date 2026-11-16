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

    /**
     * Build a declaration from untrusted catalogue data, or null when the row is malformed. The
     * config key must be a dotted path so it can name a table setting, and nothing is cast.
     *
     * @param  array<string, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $name = $data['name'] ?? null;
        $configKey = $data['configKey'] ?? null;
        $migration = $data['migration'] ?? null;

        if (! is_string($name) || preg_match('/^\w+$/D', $name) !== 1
            || ! is_string($configKey) || preg_match('/^\w+(\.\w+)+$/D', $configKey) !== 1
            || ! is_string($migration) || preg_match('/^[A-Za-z0-9_.-]+$/D', $migration) !== 1) {
            return null;
        }

        return new self(name: $name, configKey: $configKey, migration: $migration);
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
