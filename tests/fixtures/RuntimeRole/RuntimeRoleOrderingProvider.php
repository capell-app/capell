<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\RuntimeRole;

use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

final class RuntimeRoleOrderingProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        if (! $this->app instanceof Application) {
            throw new RuntimeException('Runtime role fixture did not receive a Laravel application.');
        }

        $paths = $this->app->make(RuntimeRoleCachePaths::class);
        $role = $this->app->make(RuntimeRoleResolver::class)->role();

        if (
            $this->app->getCachedConfigPath() !== $paths->config($role)
            || $this->app->getCachedPackagesPath() !== $paths->packages($role)
            || $this->app->getCachedServicesPath() !== $paths->services($role)
            || $this->app->getCachedRoutesPath() !== $paths->routes($role)
            || $this->app->getCachedEventsPath() !== $paths->events($role)
        ) {
            throw new RuntimeException('Runtime role cache paths were configured after provider registration.');
        }

        if (! $this->app->make(PackageManifest::class) instanceof RuntimeRolePackageManifest) {
            throw new RuntimeException('Runtime role package manifest was configured after provider registration.');
        }

        $this->app->instance('runtime-role.fixture.ordering', true);
    }
}
