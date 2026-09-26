<?php

declare(strict_types=1);

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Diagnostics\Checks\RuntimeRoleCheck;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;
use Composer\InstalledVersions;
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

it(
    'checks prepared runtime role provider graphs and loaded providers',
    /**
     * @param  list<class-string>  $providers
     * @param  array<class-string, bool>  $loadedProviders
     * @param  list<string>  $expectedErrors
     */
    function (RuntimeRole $role, array $providers, array $loadedProviders, array $expectedErrors): void {
        if ($role === RuntimeRole::Authoring) {
            expect(InstalledVersions::isInstalled('capell-app/frontend'))->toBeTrue();
        }

        $files = new Filesystem;
        $basePath = sys_get_temp_dir() . '/capell-runtime-role-doctor-' . bin2hex(random_bytes(6));
        $files->ensureDirectoryExists($basePath . '/bootstrap/cache/capell-runtime/' . $role->value);
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
            manifestPath: $paths->packages($role),
            sourceManifestPath: $basePath . '/bootstrap/cache/packages.php',
            role: $role,
            policy: $policy,
        );

        $files->put($paths->packages($role), '<?php return [];');
        $files->put(
            $paths->providers($role),
            '<?php return ' . var_export($providers, true) . ';',
        );
        $files->put(
            $paths->services($role),
            '<?php return ' . var_export([
                'providers' => $providers,
                'eager' => $providers,
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

        $application->shouldReceive('getCachedConfigPath')->andReturn($paths->config($role));
        $application->shouldReceive('getCachedPackagesPath')->andReturn($paths->packages($role));
        $application->shouldReceive('getCachedServicesPath')->andReturn($paths->services($role));
        $application->shouldReceive('getCachedRoutesPath')->andReturn($paths->routes($role));
        $application->shouldReceive('getCachedEventsPath')->andReturn($paths->events($role));
        $application->shouldReceive('bound')->with(PackageManifest::class)->andReturnTrue();
        $application->shouldReceive('make')->with(PackageManifest::class)->andReturn($manifest);
        $application->shouldReceive('getLoadedProviders')->andReturn($loadedProviders);

        try {
            $result = new RuntimeRoleCheck(
                application: $application,
                resolver: new RuntimeRoleResolver(RuntimeRoleSelectionData::fromConfiguredValue($role->value)),
                paths: $paths,
                policy: $policy,
            )->check();

            expect($result)
                ->passed->toBe($expectedErrors === [])
                ->and($result->evidence['errors'] ?? [])->toBe($expectedErrors);

            if ($expectedErrors !== []) {
                expect($result->message)->toContain('does not match its generated provider contract');
            }

            if ($role === RuntimeRole::Public) {
                expect($result->evidence['authoring_providers'])->toContain(AuthoringRuntimeRoleProvider::class);
            }
        } finally {
            $files->deleteDirectory($basePath);
        }
    },
)->with([
    'public rejects authoring providers' => [
        RuntimeRole::Public,
        [AuthoringRuntimeRoleProvider::class],
        [],
        ['The public provider graph contains authoring-only providers.'],
    ],
    // User-defined methods accept the value/key pair passed to array predicates,
    // even under strict types. Non-empty lists exercise both first-class callbacks.
    'authoring includes and loads Frontend after another provider' => [
        RuntimeRole::Authoring,
        [AuthoringRuntimeRoleProvider::class, FrontendServiceProvider::class],
        [AuthoringRuntimeRoleProvider::class => true, FrontendServiceProvider::class => true],
        [],
    ],
    'authoring has Frontend loaded but missing from its graph' => [
        RuntimeRole::Authoring,
        [AuthoringRuntimeRoleProvider::class],
        [AuthoringRuntimeRoleProvider::class => true, FrontendServiceProvider::class => true],
        ['The authoring provider graph does not contain Frontend, so authenticated previews cannot use the public renderer.'],
    ],
    'authoring has Frontend in its graph but not loaded' => [
        RuntimeRole::Authoring,
        [AuthoringRuntimeRoleProvider::class, FrontendServiceProvider::class],
        [AuthoringRuntimeRoleProvider::class => true],
        ['Frontend is not loaded in the authoring process, so authenticated previews cannot use the public renderer.'],
    ],
    'authoring is missing Frontend from both lists' => [
        RuntimeRole::Authoring,
        [AuthoringRuntimeRoleProvider::class],
        [AuthoringRuntimeRoleProvider::class => true],
        [
            'The authoring provider graph does not contain Frontend, so authenticated previews cannot use the public renderer.',
            'Frontend is not loaded in the authoring process, so authenticated previews cannot use the public renderer.',
        ],
    ],
    'authoring ignores Frontend marked as unloaded' => [
        RuntimeRole::Authoring,
        [AuthoringRuntimeRoleProvider::class, FrontendServiceProvider::class],
        [AuthoringRuntimeRoleProvider::class => true, FrontendServiceProvider::class => false],
        ['Frontend is not loaded in the authoring process, so authenticated previews cannot use the public renderer.'],
    ],
    'authoring rejects empty provider lists' => [
        RuntimeRole::Authoring,
        [],
        [],
        [
            'The authoring provider graph does not contain Frontend, so authenticated previews cannot use the public renderer.',
            'Frontend is not loaded in the authoring process, so authenticated previews cannot use the public renderer.',
        ],
    ],
]);

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
