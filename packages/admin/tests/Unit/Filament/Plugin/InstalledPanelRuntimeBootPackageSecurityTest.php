<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StandaloneCallbackRouteProvider;
use Override;

/** The security package registers before the provider that owns the standalone callback. */
final class InstalledPanelRuntimeBootPackageSecurityTest extends InstalledPanelRuntimePackageSecurityTestCase
{
    #[Override]
    protected function runtimeProviders(): array
    {
        return [PackageSecurityDenyRuntimeProvider::class, StandaloneCallbackRouteProvider::class];
    }
}
