<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Enums\RuntimeRole;
use Illuminate\Contracts\Foundation\Application;

final readonly class RuntimeRoleCachePaths
{
    public function __construct(private Application $application) {}

    public function directory(): string
    {
        return $this->application->bootstrapPath('cache/capell-runtime');
    }

    public function packages(RuntimeRole $role): string
    {
        return $this->roleDirectory($role) . '/packages.php';
    }

    public function config(RuntimeRole $role): string
    {
        return $this->roleDirectory($role) . '/config.php';
    }

    public function providers(RuntimeRole $role): string
    {
        return $this->roleDirectory($role) . '/providers.php';
    }

    public function services(RuntimeRole $role): string
    {
        return $this->roleDirectory($role) . '/services.php';
    }

    public function routes(RuntimeRole $role): string
    {
        return $this->roleDirectory($role) . '/routes-v7.php';
    }

    public function events(RuntimeRole $role): string
    {
        return $this->roleDirectory($role) . '/events.php';
    }

    public function metadata(): string
    {
        return $this->directory() . '/manifest.php';
    }

    /** @return list<string> */
    public function generatedProviderPaths(): array
    {
        $paths = [$this->metadata()];

        foreach (RuntimeRole::deploymentRoles() as $role) {
            $paths[] = $this->packages($role);
            $paths[] = $this->providers($role);
            $paths[] = $this->services($role);
        }

        return $paths;
    }

    private function roleDirectory(RuntimeRole $role): string
    {
        return $this->directory() . '/' . $role->value;
    }
}
