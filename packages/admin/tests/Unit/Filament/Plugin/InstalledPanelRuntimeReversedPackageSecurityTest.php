<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StandaloneCallbackRouteProvider;
use Override;

/** The same coverage when the callback provider registers before the security package. */
final class InstalledPanelRuntimeReversedPackageSecurityTest extends InstalledPanelRuntimePackageSecurityTestCase
{
    #[Override]
    protected function runtimeProviders(): array
    {
        return [StandaloneCallbackRouteProvider::class, PackageSecurityDenyRuntimeProvider::class];
    }
}
