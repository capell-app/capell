<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildInstalledPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;

interface ProjectBuildPackageInstaller
{
    public function installedRelease(string $package): ?ProjectBuildInstalledPackageData;

    public function install(ProjectBuildPackageData $package): ProjectBuildInstalledPackageData;
}
