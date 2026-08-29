<?php

declare(strict_types=1);

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Bootstrap\CloudInstallContext;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Mockery\MockInterface;

it('does not register runtime capabilities for disabled non-core manifest v3 packages', function (): void {
    $registry = packageLoaderV3Registry('vendor/disabled-package', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/disabled-package')->andReturnFalse();

    expect(packageV3Loader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class)
        ->not->toContain(FilesystemServiceProvider::class, HashServiceProvider::class);
});

it('registers runtime admin frontend and auth capabilities for enabled manifest v3 packages', function (): void {
    $registry = packageLoaderV3Registry('vendor/enabled-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/enabled-package')->andReturnTrue();

    expect(packageV3Loader($registry)->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('registers every trusted core capability without runtime gate checks', function (): void {
    $registry = packageLoaderV3Registry('capell-app/core', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    expect(packageV3Loader($registry)->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('registers all manifest-v3 capabilities selected by the cloud install process before package state exists', function (): void {
    $registry = packageLoaderV3Registry('vendor/cloud-selected-package', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
        'admin' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    expect(packageV3Loader($registry, CloudInstallContext::forCloudPackages(['vendor/cloud-selected-package']))->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('does not ask the package lifecycle ledger about non-selected cloud install manifests', function (): void {
    $registry = packageLoaderV3Registry('vendor/cloud-unselected-package', [
        'metadata' => [AuthServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    expect(packageV3Loader($registry, CloudInstallContext::forCloudPackages(['vendor/other-package']))->collectProviders())
        ->toBe([AuthServiceProvider::class]);
});

it('uses persisted lifecycle state for non-selected packages after a cloud install created its ledger', function (): void {
    $registry = packageLoaderV3Registry('vendor/cloud-enabled-package', [
        'runtime' => [FilesystemServiceProvider::class],
    ]);
    $schemaState = resolve(RuntimeSchemaState::class);
    $schemaState->forgetTable('capell_extensions');

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('bound')->once()->with('db')->andReturnTrue();
    $application->shouldReceive('make')->once()->with(RuntimeSchemaState::class)->andReturn($schemaState);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/cloud-enabled-package')->andReturnTrue();

    expect(new CapellPackageLoader(
        $application,
        $registry,
        CloudInstallContext::forCloudPackages(['vendor/other-package']),
    )->collectProviders())->toBe([FilesystemServiceProvider::class]);
});

it('deduplicates capabilities declared for more than one request surface', function (): void {
    $registry = packageLoaderV3Registry('vendor/shared-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [AuthServiceProvider::class],
        'frontend' => [AuthServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/shared-package')->andReturnTrue();

    expect(packageV3Loader($registry)->collectProviders())
        ->toBe([AuthServiceProvider::class]);
});

it('records only the selected buckets for a shared provider in the public role', function (): void {
    $registry = packageLoaderV3Registry('vendor/shared-public-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [AuthServiceProvider::class],
        'frontend' => [AuthServiceProvider::class],
    ]);
    $receipts = new ExtensionContributionReceiptRegistry;

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('register')->once()->with(AuthServiceProvider::class)->andReturnUsing(
        fn (): mixed => $receipts->recordFromContext(
            ExtensionContributionType::Model,
            'model:' . stdClass::class,
            stdClass::class,
            AuthServiceProvider::class,
        ),
    );

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/shared-public-package')->andReturnTrue();

    (new CapellPackageLoader(
        $application,
        $registry,
        runtimeRoleResolver: new RuntimeRoleResolver(new RuntimeRoleSelectionData(
            role: RuntimeRole::Public,
            configuredValue: RuntimeRole::Public->value,
            valid: true,
        )),
        receipts: $receipts,
    ))->loadProviders();

    expect($receipts->loadedBuckets('vendor/shared-public-package'))
        ->toBe(['runtime', 'frontend'])
        ->and(array_map(
            static fn (object $receipt): string => $receipt->providerBucket,
            $receipts->forPackage('vendor/shared-public-package'),
        ))->toBe(['runtime']);
});

it('does not mark an admin bucket as booted for a disabled install-only context', function (): void {
    $registry = packageLoaderV3Registry('vendor/disabled-install-package', [
        'install' => [AuthServiceProvider::class],
        'admin' => [AuthServiceProvider::class],
    ]);
    $receipts = new ExtensionContributionReceiptRegistry;

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('register')->once()->with(AuthServiceProvider::class)->andReturnUsing(
        fn (): mixed => $receipts->recordFromContext(
            ExtensionContributionType::Model,
            'model:' . stdClass::class,
            stdClass::class,
            AuthServiceProvider::class,
        ),
    );

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/disabled-install-package')->andReturnFalse();

    (new CapellPackageLoader(
        $application,
        $registry,
        receipts: $receipts,
    ))->loadProviders();

    expect($receipts->loadedBuckets('vendor/disabled-install-package'))->toBe(['install'])
        ->and(array_map(
            static fn (object $receipt): string => $receipt->providerBucket,
            $receipts->forPackage('vendor/disabled-install-package'),
        ))->toBe(['install']);
});

it('loads only metadata runtime frontend and auth capabilities in the public role', function (): void {
    $registry = packageLoaderV3Registry('vendor/public-package', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
        'admin' => [HashServiceProvider::class],
        'frontend' => [CacheServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/public-package')->andReturnTrue();

    expect(packageV3Loader($registry, runtimeRole: RuntimeRole::Public)->collectProviders())
        ->toBe([
            AuthServiceProvider::class,
            FilesystemServiceProvider::class,
            CacheServiceProvider::class,
            HashServiceProvider::class,
        ]);
});

it('does not load install capabilities for disabled packages in the public role', function (): void {
    $registry = packageLoaderV3Registry('vendor/disabled-public-package', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/disabled-public-package')->andReturnFalse();

    expect(packageV3Loader($registry, runtimeRole: RuntimeRole::Public)->collectProviders())
        ->toBe([AuthServiceProvider::class]);
});

/** @param array<string, list<class-string>> $providers */
function packageLoaderV3Registry(string $name, array $providers): CapellPackageRegistry
{
    $registry = new CapellPackageRegistry;
    $registry->fill([
        $name => CapellManifestData::fromArray(capellManifestV3Array(
            name: $name,
            surfaces: ['admin', 'frontend'],
            providers: $providers,
        )),
    ]);

    return $registry;
}

function packageV3Loader(
    CapellPackageRegistry $registry,
    ?CloudInstallContext $cloudInstallContext = null,
    RuntimeRole $runtimeRole = RuntimeRole::Combined,
): CapellPackageLoader {
    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);

    if ($cloudInstallContext instanceof CloudInstallContext) {
        $application->shouldReceive('bound')->with('db')->zeroOrMoreTimes()->andReturnFalse();
    }

    return new CapellPackageLoader(
        $application,
        $registry,
        $cloudInstallContext,
        new RuntimeRoleResolver(new RuntimeRoleSelectionData(
            role: $runtimeRole,
            configuredValue: $runtimeRole->value,
            valid: true,
        )),
    );
}
