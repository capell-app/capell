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
        throw_unless($this->app instanceof Application, RuntimeException::class, 'Runtime role fixture did not receive a Laravel application.');

        $paths = $this->app->make(RuntimeRoleCachePaths::class);
        $role = $this->app->make(RuntimeRoleResolver::class)->role();

        throw_if($this->app->getCachedConfigPath() !== $paths->config($role)
        || $this->app->getCachedPackagesPath() !== $paths->packages($role)
        || $this->app->getCachedServicesPath() !== $paths->services($role)
        || $this->app->getCachedRoutesPath() !== $paths->routes($role)
        || $this->app->getCachedEventsPath() !== $paths->events($role), RuntimeException::class, 'Runtime role cache paths were configured after provider registration.');

        throw_unless($this->app->make(PackageManifest::class) instanceof RuntimeRolePackageManifest, RuntimeException::class, 'Runtime role package manifest was configured after provider registration.');

        $this->app->instance('runtime-role.fixture.ordering', true);
    }
}
