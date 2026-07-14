<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\Assets\FrontendDependencyPlanData;
use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;
use Capell\Frontend\Enums\FrontendPackageDependencyType;
use Capell\Frontend\Enums\FrontendPackageManager;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveFrontendDependencyPlanAction
{
    use AsObject;

    public function __construct(private readonly FrontendPackageDependencyRegistry $registry) {}

    public function handle(?string $basePath = null): FrontendDependencyPlanData
    {
        $basePath ??= base_path();
        $packageJson = $this->packageJson($basePath);
        $manager = $this->manager($basePath, $packageJson);
        $requirements = [];

        foreach ($this->registry->all() as $dependency) {
            $requirements[$dependency->name] ??= [];
            $requirements[$dependency->name][] = $dependency;
        }

        $runtime = [];
        $development = [];
        $resolved = [];
        $diagnostics = [];

        foreach ($requirements as $name => $dependencies) {
            $constraints = array_values(array_unique(array_map(static fn (FrontendPackageDependencyData $dependency): string => $dependency->versionConstraint, $dependencies)));
            $applicationVersion = $packageJson['dependencies'][$name] ?? $packageJson['devDependencies'][$name] ?? null;
            $configuredVersion = config('capell-frontend.package_dependency_resolutions.' . $name);

            if (is_string($applicationVersion) && $applicationVersion !== '') {
                $version = $applicationVersion;
                $resolution = 'application';
            } elseif (count($constraints) === 1) {
                $version = $constraints[0];
                $resolution = 'identical';
            } elseif (is_string($configuredVersion) && $configuredVersion !== '') {
                $version = $configuredVersion;
                $resolution = 'configured';
            } else {
                throw new FrontendResourcePlanException("Divergent frontend dependency constraints for [{$name}] require an application resolution.");
            }

            $type = collect($dependencies)->contains(static fn (FrontendPackageDependencyData $dependency): bool => $dependency->type === FrontendPackageDependencyType::Runtime)
                ? FrontendPackageDependencyType::Runtime
                : FrontendPackageDependencyType::Development;
            $specifier = $name . '@' . $version;
            if ($type === FrontendPackageDependencyType::Runtime) {
                $runtime[] = $specifier;
            } else {
                $development[] = $specifier;
            }
            $resolved[$name] = [
                'version' => $version,
                'type' => $type->value,
                'resolution' => $resolution,
                'constraints' => array_map(static fn (FrontendPackageDependencyData $dependency): array => [
                    'constraint' => $dependency->versionConstraint,
                    'package' => $dependency->package,
                    'type' => $dependency->type->value,
                ], $dependencies),
            ];

            if (count($constraints) > 1 || $resolution === 'application') {
                $diagnostics[] = ['code' => 'dependency-resolution', 'name' => $name, ...$resolved[$name]];
            }
        }

        ksort($resolved);
        sort($runtime);
        sort($development);

        return new FrontendDependencyPlanData(
            manager: $manager,
            runtimeCommand: $runtime === [] ? [] : $manager->installCommand(FrontendPackageDependencyType::Runtime, $runtime),
            developmentCommand: $development === [] ? [] : $manager->installCommand(FrontendPackageDependencyType::Development, $development),
            requirements: $resolved,
            diagnostics: $diagnostics,
        );
    }

    /** @return array<string, mixed> */
    private function packageJson(string $basePath): array
    {
        $path = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'package.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $packageJson */
    private function manager(string $basePath, array $packageJson): FrontendPackageManager
    {
        $lockfiles = [
            FrontendPackageManager::Npm->value => 'package-lock.json',
            FrontendPackageManager::Pnpm->value => 'pnpm-lock.yaml',
            FrontendPackageManager::Yarn->value => 'yarn.lock',
            FrontendPackageManager::Bun->value => is_file($basePath . '/bun.lock') ? 'bun.lock' : 'bun.lockb',
        ];
        $detected = array_keys(array_filter($lockfiles, static fn (string $lockfile): bool => is_file(rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $lockfile)));

        if (count($detected) > 1) {
            throw new FrontendResourcePlanException('Conflicting frontend package-manager lockfiles were detected: ' . implode(', ', $detected) . '.');
        }

        $declared = $packageJson['packageManager'] ?? null;
        $declaredName = is_string($declared) ? explode('@', $declared, 2)[0] : null;
        $declaredManager = is_string($declaredName) ? FrontendPackageManager::tryFrom($declaredName) : null;

        if (is_string($declared) && ! $declaredManager instanceof FrontendPackageManager) {
            throw new FrontendResourcePlanException("Unsupported packageManager declaration [{$declared}].");
        }

        if ($declaredManager instanceof FrontendPackageManager && $detected !== [] && $detected[0] !== $declaredManager->value) {
            throw new FrontendResourcePlanException("packageManager [{$declaredManager->value}] conflicts with the detected [{$detected[0]}] lockfile.");
        }

        return $declaredManager ?? ($detected !== [] ? FrontendPackageManager::from($detected[0]) : FrontendPackageManager::Npm);
    }
}
