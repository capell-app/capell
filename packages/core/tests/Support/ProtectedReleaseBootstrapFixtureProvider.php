<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\ProtectedReleaseBootstrapProvider;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;

final class ProtectedReleaseBootstrapFixtureProvider extends ProtectedReleaseBootstrapProvider
{
    protected function legacyProviders(): array
    {
        return [ManifestBootstrapFrontendProvider::class, AuthoringRuntimeRoleProvider::class];
    }
}
