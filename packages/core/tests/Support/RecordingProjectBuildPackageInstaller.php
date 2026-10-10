<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\ProjectBuild\ProjectBuildPackageInstaller;
use Capell\Core\Data\ProjectBuild\ProjectBuildInstalledPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;
use Override;

class RecordingProjectBuildPackageInstaller implements ProjectBuildPackageInstaller
{
    /** @var array<string, ProjectBuildInstalledPackageData> */
    public array $installed = [];

    /** @var list<string> */
    public array $installCalls = [];

    #[Override]
    public function installedRelease(string $package): ?ProjectBuildInstalledPackageData
    {
        return $this->installed[$package] ?? null;
    }

    #[Override]
    public function install(ProjectBuildPackageData $package): ProjectBuildInstalledPackageData
    {
        $this->installCalls[] = $package->name;

        return $this->installed[$package->name] = new ProjectBuildInstalledPackageData(
            name: $package->name,
            version: $package->version,
            releaseIdentity: $package->releaseIdentity,
        );
    }
}
