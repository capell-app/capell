<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ProjectBuild;

interface ProjectBuildManifestMigration
{
    public function fromVersion(): int;

    public function toVersion(): int;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function migrate(array $payload): array;
}
