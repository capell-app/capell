<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Support\ServiceProvider;

final class ColdCloudInstallRuntimeProviderFixture extends ServiceProvider
{
    public function boot(PackageSurfaceRegistrar $surface): void
    {
        $surface->outboundEvent(new OutboundEventDefinitionData(
            name: 'cold-cloud-install.surface-ready',
            version: 1,
            payloadClass: PageTypeData::class,
            description: 'Confirms the selected cloud package booted before registry freeze.',
            ownerPackage: 'vendor/cold-cloud-install',
        ));
    }
}
