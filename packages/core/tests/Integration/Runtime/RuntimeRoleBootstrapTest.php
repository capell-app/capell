<?php

declare(strict_types=1);

use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;
use Capell\Tests\Fixtures\RuntimeRole\FrontendPreviewRuntimeRoleProvider;
use Capell\Tests\Fixtures\RuntimeRole\RuntimeRoleOrderingProvider;
use Symfony\Component\Process\Process;

it('selects role-specific provider graphs before Laravel registers providers', function (): void {
    $combined = bootRuntimeRoleFixture('combined');
    $public = bootRuntimeRoleFixture('public');
    $authoring = bootRuntimeRoleFixture('authoring');

    expect($combined['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class, RuntimeRoleOrderingProvider::class)
        ->and($authoring['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class, RuntimeRoleOrderingProvider::class)
        ->and($public['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class)
        ->not->toContain(AuthoringRuntimeRoleProvider::class)
        ->toContain(RuntimeRoleOrderingProvider::class)
        ->and($public['package_manifest'])->toBe(RuntimeRolePackageManifest::class)
        ->and($combined['services_cache'])->not->toBe($public['services_cache'])
        ->and($public['services_cache'])->not->toBe($authoring['services_cache']);

    foreach ([$combined, $public, $authoring] as $result) {
        foreach (['config_cache', 'packages_cache', 'services_cache', 'routes_cache', 'events_cache'] as $cache) {
            expect($result[$cache])->toContain('/capell-runtime/' . $result['role'] . '/');
        }
    }
});

it('falls back safely to combined while retaining an invalid role diagnostic', function (): void {
    $result = bootRuntimeRoleFixture('not-a-role');

    expect($result['role'])->toBe('combined')
        ->and($result['valid'])->toBeFalse()
        ->and($result['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class, RuntimeRoleOrderingProvider::class);
});

it('re-filters stale generated manifests before public provider registration', function (): void {
    $result = bootRuntimeRoleFixture('public', 'stale');

    expect($result['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class)
        ->not->toContain(AuthoringRuntimeRoleProvider::class);
});

it('preserves and filters ApplicationBuilder providers when the bootstrap manifest is empty', function (): void {
    $combined = bootRuntimeRoleFixture('combined', 'custom');
    $public = bootRuntimeRoleFixture('public', 'custom');

    expect($combined['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class, RuntimeRoleOrderingProvider::class)
        ->and($public['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class)
        ->not->toContain(AuthoringRuntimeRoleProvider::class)
        ->toContain(RuntimeRoleOrderingProvider::class);
});

it('configures custom application factories after environment and configuration are resolved', function (): void {
    $result = bootRuntimeRoleFixture('combined', 'resolved');

    expect($result['package_manifest'])->toBe(RuntimeRolePackageManifest::class);

    foreach (['config_cache', 'packages_cache', 'services_cache', 'routes_cache', 'events_cache'] as $cache) {
        expect($result[$cache])->toContain('/capell-runtime/combined/');
    }
});

it('boots the actual Testbench runtime-role bootstrap before provider registration', function (): void {
    $result = bootRuntimeRoleTestbenchFixture('public');

    expect($result['role'])->toBe('public')
        ->and($result['ordering_registered'])->toBeTrue()
        ->and($result['package_manifest'])->toBe(RuntimeRolePackageManifest::class);

    foreach (['config_cache', 'packages_cache', 'services_cache', 'routes_cache', 'events_cache'] as $cache) {
        expect($result[$cache])->toContain('/capell-runtime/public/');
    }
});

/** @return array<string, mixed> */
function bootRuntimeRoleFixture(string $role, ?string $manifestState = null): array
{
    $repositoryPath = dirname(__DIR__, 5);
    $process = new Process([
        PHP_BINARY,
        $repositoryPath . '/tests/fixtures/RuntimeRole/boot-runtime-role.php',
        $role,
        ...($manifestState === null ? [] : [$manifestState]),
    ], $repositoryPath);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect($result)->toBeArray();

    return $result;
}

/** @return array<string, mixed> */
function bootRuntimeRoleTestbenchFixture(string $role): array
{
    $repositoryPath = dirname(__DIR__, 5);
    $bootstrapPath = $repositoryPath . '/tests/Support/runtime-role-testbench-bootstrap.php';
    $provider = RuntimeRoleOrderingProvider::class;
    $process = new Process([
        PHP_BINARY,
        '-r',
        sprintf(
            <<<'PHP'
            require %s;

            $basePath = \Capell\Tests\Support\IsolatedTestbenchSkeleton::basePath();
            $providersPath = $basePath . '/bootstrap/providers.php';
            $providers = require $providersPath;
            $providers[] = %s;
            file_put_contents($providersPath, '<?php return ' . var_export(array_values(array_unique($providers)), true) . ';');

            $application = require %s;
            $paths = $application->make(\Capell\Core\Support\Runtime\RuntimeRoleCachePaths::class);
            $role = $application->make(\Capell\Core\Support\Runtime\RuntimeRoleResolver::class)->role();

            echo json_encode([
                'role' => $role->value,
                'config_cache' => $application->getCachedConfigPath(),
                'packages_cache' => $application->getCachedPackagesPath(),
                'services_cache' => $application->getCachedServicesPath(),
                'routes_cache' => $application->getCachedRoutesPath(),
                'events_cache' => $application->getCachedEventsPath(),
                'package_manifest' => $application->make(\Illuminate\Foundation\PackageManifest::class)::class,
                'ordering_registered' => $application->bound('runtime-role.fixture.ordering'),
                'expected_config_cache' => $paths->config($role),
            ], JSON_THROW_ON_ERROR);
            PHP,
            var_export($repositoryPath . '/vendor/autoload.php', true),
            var_export($provider, true),
            var_export($bootstrapPath, true),
        ),
    ], $repositoryPath, [
        'CAPELL_RUNTIME_ROLE' => $role,
        'UNIQUE_TEST_TOKEN' => 'runtime-role-testbench-' . bin2hex(random_bytes(6)),
    ]);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect($result)->toBeArray()
        ->and($result['config_cache'])->toBe($result['expected_config_cache']);

    return $result;
}
