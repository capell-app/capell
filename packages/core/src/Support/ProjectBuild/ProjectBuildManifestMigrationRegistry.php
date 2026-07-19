<?php

declare(strict_types=1);

namespace Capell\Core\Support\ProjectBuild;

use Capell\Core\Contracts\ProjectBuild\ProjectBuildManifestMigration;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ProjectBuildManifestMigrationRegistry
{
    /** @var array<int, ProjectBuildManifestMigration> */
    private array $migrations = [];

    public function register(ProjectBuildManifestMigration $migration): void
    {
        $from = $migration->fromVersion();
        $to = $migration->toVersion();

        throw_if($from < 0 || $to <= $from, LogicException::class, 'Project build manifest migrations must move forward from a non-negative version.');
        throw_if(isset($this->migrations[$from]), LogicException::class, sprintf('A project build manifest migration is already registered from version [%d].', $from));

        $this->migrations[$from] = $migration;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function migrate(array $payload, int $targetVersion): array
    {
        $version = $payload['schemaVersion'] ?? null;
        if (! is_int($version)) {
            throw ValidationException::withMessages(['schemaVersion' => 'The project build manifest schema version must be an integer.']);
        }

        if ($version > $targetVersion) {
            throw ValidationException::withMessages(['schemaVersion' => 'The project build manifest uses a newer unsupported schema version.']);
        }

        while ($version < $targetVersion) {
            $migration = $this->migrations[$version] ?? null;
            if (! $migration instanceof ProjectBuildManifestMigration || $migration->toVersion() > $targetVersion) {
                throw ValidationException::withMessages(['schemaVersion' => sprintf('No compatible project build manifest migration is registered from version [%d].', $version)]);
            }

            $payload = $migration->migrate($payload);
            $version = $payload['schemaVersion'] ?? null;
            if ($version !== $migration->toVersion()) {
                throw ValidationException::withMessages(['schemaVersion' => 'A project build manifest migration returned an unexpected schema version.']);
            }
        }

        return $payload;
    }
}
