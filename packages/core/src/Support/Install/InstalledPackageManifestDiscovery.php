<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;

class InstalledPackageManifestDiscovery
{
    public function __construct(
        private readonly ManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array<string, CapellManifestData>
     */
    public function discover(): array
    {
        return $this->manifestLoader->discover();
    }
}
