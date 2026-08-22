<?php

declare(strict_types=1);

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Diagnostics\Checks\RuntimeRoleCheck;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Mockery\MockInterface;

it('fails doctor for an invalid configured runtime role', function (): void {
    $fixture = runtimeRoleDoctorFixture('invalid-role');

    expect($fixture['check']->check())
        ->passed->toBeFalse()
        ->id->toBe('core.runtime.role')
        ->message->toContain('invalid-role')
        ->remediation->toContain('combined, public, or authoring');

    $fixture['files']->deleteDirectory($fixture['path']);
});

it('fails doctor when a split role has not been prepared during deployment', function (): void {
    $fixture = runtimeRoleDoctorFixture('public');

    expect($fixture['check']->check())
        ->passed->toBeFalse()
        ->message->toContain('public')
        ->remediation->toContain('capell:package-cache');

    $fixture['files']->deleteDirectory($fixture['path']);
});

it('keeps an unprepared combined role backward compatible', function (): void {
    $fixture = runtimeRoleDoctorFixture('combined');

    expect($fixture['check']->check())
        ->passed->toBeTrue()
        ->message->toContain('backward-compatible combined runtime');

    $fixture['files']->deleteDirectory($fixture['path']);
});

it('fails doctor when a prepared public manifest contains an authoring provider', function (): void {
    $files = new Filesystem;
    $basePath = sys_get_temp_dir() . '/capell-runtime-role-doctor-' . bin2hex(random_bytes(6));
    $files->ensureDirectoryExists($basePath . '/bootstrap/cache/capell-runtime/public');
    $files->put($basePath . '/bootstrap/cache/packages.php', '<?php return [];');
    $files->put($basePath . '/bootstrap/providers.php', '<?php return [];');

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('bootstrapPath')->andReturnUsing(
        static fn (?string $path = null): string => $basePath . '/bootstrap' . ($path === null ? '' : '/' . $path),
    );
    $paths = new RuntimeRoleCachePaths($application);
    $policy = new RuntimeRoleProviderPolicy;
    $manifest = new RuntimeRolePackageManifest(
        files: $files,
        basePath: $basePath,
        manifestPath: $paths->packages(RuntimeRole::Public),
        sourceManifestPath: $basePath . '/bootstrap/cache/packages.php',
        role: RuntimeRole::Public,
        policy: $policy,
    );

    $files->put($paths->packages(RuntimeRole::Public), '<?php return [];');
    $files->put(
        $paths->providers(RuntimeRole::Public),
        '<?php return ' . var_export([AuthoringRuntimeRoleProvider::class], true) . ';',
    );
    $files->put(
        $paths->services(RuntimeRole::Public),
        '<?php return ' . var_export([
            'providers' => [AuthoringRuntimeRoleProvider::class],
            'eager' => [AuthoringRuntimeRoleProvider::class],
            'deferred' => [],
            'when' => [],
        ], true) . ';',
    );
    $files->put(
        $paths->metadata(),
        '<?php return ' . var_export([
            'schema_version' => 1,
            'source_packages_sha256' => hash_file('sha256', $basePath . '/bootstrap/cache/packages.php'),
            'bootstrap_providers_sha256' => hash_file('sha256', $basePath . '/bootstrap/providers.php'),
            'roles' => array_map(
                static fn (RuntimeRole $role): string => $role->value,
                RuntimeRole::deploymentRoles(),
            ),
        ], true) . ';',
    );

    $application->shouldReceive('getCachedConfigPath')->andReturn($paths->config(RuntimeRole::Public));
    $application->shouldReceive('getCachedPackagesPath')->andReturn($paths->packages(RuntimeRole::Public));
    $application->shouldReceive('getCachedServicesPath')->andReturn($paths->services(RuntimeRole::Public));
    $application->shouldReceive('getCachedRoutesPath')->andReturn($paths->routes(RuntimeRole::Public));
    $application->shouldReceive('getCachedEventsPath')->andReturn($paths->events(RuntimeRole::Public));
    $application->shouldReceive('bound')->with(PackageManifest::class)->andReturnTrue();
    $application->shouldReceive('make')->with(PackageManifest::class)->andReturn($manifest);
    $application->shouldReceive('getLoadedProviders')->andReturn([]);

    try {
        $result = new RuntimeRoleCheck(
            application: $application,
            resolver: new RuntimeRoleResolver(RuntimeRoleSelectionData::fromConfiguredValue('public')),
            paths: $paths,
            policy: $policy,
        )->check();

        expect($result)
            ->passed->toBeFalse()
            ->message->toContain('does not match its generated provider contract')
            ->and($result->evidence['authoring_providers'])->toContain(AuthoringRuntimeRoleProvider::class);
    } finally {
        $files->deleteDirectory($basePath);
    }
});

/**
 * @return array{check: RuntimeRoleCheck, files: Filesystem, path: string}
 */
function runtimeRoleDoctorFixture(string $configuredRole): array
{
    $files = new Filesystem;
    $application = app();

    $resolver = new RuntimeRoleResolver(RuntimeRoleSelectionData::fromConfiguredValue($configuredRole));
    $paths = new RuntimeRoleCachePaths($application);
    $files->deleteDirectory($paths->directory());

    return [
        'check' => new RuntimeRoleCheck(
            application: $application,
            resolver: $resolver,
            paths: $paths,
            policy: new RuntimeRoleProviderPolicy,
        ),
        'files' => $files,
        'path' => $paths->directory(),
    ];
}
