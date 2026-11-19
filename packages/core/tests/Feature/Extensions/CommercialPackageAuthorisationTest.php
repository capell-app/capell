<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\Marketplace\ApplyMarketplaceIssuanceAction;
use Capell\Core\Actions\Marketplace\SendExtensionHealthReportAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\MarketplacePayloadSigner;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Testing\Http\FakeHostAddressResolver;
use Capell\Core\Tests\Support\ManifestBootstrapFrontendProvider;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceExtensionEntitlements;
use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\ProviderRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    FakeHostAddressResolver::bind();
    $this->commercialBase = app()->basePath();
    $this->commercialRoot = sys_get_temp_dir() . '/capell-commercial-' . bin2hex(random_bytes(8));
    $root = $this->commercialRoot;
    File::ensureDirectoryExists($root . '/vendor/composer');
    File::ensureDirectoryExists($root . '/bootstrap/cache');
    $this->commercialInstalledVersions = InstalledVersions::getAllRawData()[0];
    app()->setBasePath($root);
    config()->set('app.url', 'https://commercial.test');
    config()->set('capell.extensions.verification.strict', true);
    File::delete(resolve(CommercialCatalogueStore::class)->path());
    resolve(ExtensionCommercialPolicy::class)->clear();
    // Provisioning the issuer key switches enforcement on; most tests run enforced.
    commercialReceipt();
    installCommercialFixture($root, 'vendor/commercial');
    $this->commercialCalls = 0;
    $test = $this;
    Artisan::command('test:commercial-install', function () use ($test): int {
        $test->commercialCalls++;

        return 0;
    });
});

function installCommercialFixture(string $root, string $name): void
{
    $path = $root . '/vendor/' . $name;
    File::ensureDirectoryExists($path . '/src');
    $manifest = capellManifestV3Array(
        name: $name,
        surfaces: ['frontend'],
        providers: ['frontend' => [ManifestBootstrapFrontendProvider::class]],
    );
    // A copied manifest's free claim cannot lower Composer's commercial requirement.
    $manifest['product']['tier'] = 'free';
    $manifest['version'] = '1.0.0';
    $manifest['commands']['install'] = 'test:commercial-install';
    $manifest['providers']['frontend'] = [];
    $composer = [
        'name' => $name, 'version' => '1.0.0', 'license' => ['proprietary'],
        'type' => 'library', 'install-path' => '../' . $name, 'autoload' => ['psr-4' => ['Vendor\\Commercial\\' => 'src/']],
        'extra' => ['laravel' => ['providers' => [ManifestBootstrapFrontendProvider::class]]],
    ];
    File::put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$composer]], JSON_THROW_ON_ERROR));
    File::put($path . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    File::put($path . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    File::put($root . '/bootstrap/cache/capell-package-manifests.php', '<?php return ' . var_export([$name => $manifest], true) . ';');
    recordCommercialInstalledVersion($path, $name, '1.0.0.0');
    CapellCore::clearPackages();
    CapellCore::registerManifestPackage(resolve(ManifestLoader::class)->load($path . '/capell.json'));
}

function recordCommercialInstalledVersion(?string $path, string $name, ?string $version): void
{
    // Composer appends the reloaded fixture data after the registered autoloader data.
    $datasets = InstalledVersions::getAllRawData();
    $installed = end($datasets);
    assert(is_array($installed));
    $installed['versions'][$name] = [
        'version' => $version,
        'pretty_version' => $version === '1.0.0.0' ? '1.0.0' : $version,
        'install_path' => $path,
        'type' => 'library',
        'aliases' => [],
        'dev_requirement' => false,
    ];
    InstalledVersions::reload($installed);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function commercialLicenceManifest(array $overrides = []): array
{
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
    $manifest = array_replace([
        'manifest_version' => 1, 'purpose' => 'capell-extension-licence-manifest',
        'composer_name' => 'vendor/commercial', 'version_constraint' => '^1.0',
        'is_paid' => false, 'issued_at' => now()->subDay()->toIso8601String(),
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
    ], $overrides);
    $manifest['signature']['value'] = base64_encode(sodium_crypto_sign_detached(CanonicalizeProjectBuildManifestSigningInputAction::run($manifest), sodium_crypto_sign_secretkey($keys)));

    return $manifest;
}

/** @param array<string, mixed> $manifest */
function writeCommercialLicenceManifest(string $root, array $manifest, string $name = 'vendor/commercial'): void
{
    $path = $root . '/vendor/' . $name;
    File::ensureDirectoryExists($path);
    File::put($path . '/capell-licence.json', json_encode($manifest, JSON_THROW_ON_ERROR));
}

/** @return array<string, mixed> */
function commercialReceipt(array $overrides = []): array
{
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(keys: ['test-issuer' => base64_encode(sodium_crypto_sign_publickey($keys))]));
    $receipt = array_replace([
        'receipt_version' => 2, 'purpose' => 'capell-installed-receipt', 'receipt_id' => 'receipt-commercial',
        'composer_name' => 'vendor/commercial', 'package_version' => '1.0.0', 'package_identity' => 'commercial-release',
        'instance_id' => 'ddda8b4c-3850-4d20-9cf4-460c9364c413', 'domain' => 'commercial.test',
        'issued_at' => now()->subDay()->toIso8601String(), 'runtime_valid_until' => now()->addDays(20)->toIso8601String(),
        'perpetual_installed_runtime' => true, 'runtime_revoked' => false,
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
    ], $overrides);
    $receipt['signature']['value'] = base64_encode(sodium_crypto_sign_detached(CanonicalizeProjectBuildManifestSigningInputAction::run($receipt), sodium_crypto_sign_secretkey($keys)));

    return $receipt;
}

function connectCommercialMarketplace(): void
{
    if (! Schema::hasTable('marketplace_instances')) {
        (require __DIR__ . '/../../../../marketplace/database/migrations/2026_05_10_190837_01_create_marketplace_instances_table.php')->up();
    }

    MarketplaceInstance::query()->create([
        'instance_id' => 'ddda8b4c-3850-4d20-9cf4-460c9364c413',
        'signing_secret_encrypted' => 'commercial-secret', 'last_heartbeat_at' => now(),
    ]);
    config()->set('capell-marketplace.marketplace.base_url', 'https://marketplace.test');
    config()->set('capell.extensions.marketplace_instance_id', 'ddda8b4c-3850-4d20-9cf4-460c9364c413');

    app()->bind(ExtensionEntitlements::class, MarketplaceExtensionEntitlements::class);
}

it('authorises initial CLI installation using the real signed Marketplace entitlement consumer', function (string $status, bool $paid): void {
    connectCommercialMarketplace();
    Http::fake(['*' => Http::response(['data' => [
        'licence_status' => $status, 'can_install' => true, 'runtime_allowed' => true,
        'signed_activation' => ['installed_receipt' => commercialReceipt()],
    ]])]);
    expect(Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toBe(0)
        ->and($this->commercialCalls)->toBe(1)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
    $extension = CapellExtension::query()->where('composer_name', 'vendor/commercial')->sole();
    expect($extension->is_paid_marketplace_extension)->toBe($paid)
        ->and($extension->marketplace_runtime_status)->toBe('active');
    Http::assertSent(fn ($request): bool => str_ends_with((string) $request->url(), '/licence-decision')
        && $request['domain'] === 'commercial.test' && $request['action'] === 'install'
        && is_string($request->header('X-Capell-Signature')[0] ?? null));
})->with(['paid' => ['active', true], 'proprietary free' => ['free', false]]);

it('preserves verified paid offline continuity through enable without requesting renewal', function (): void {
    connectCommercialMarketplace();
    Http::preventStrayRequests();
    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Disabled,
        'is_paid_marketplace_extension' => true, 'marketplace_runtime_status' => 'expired', 'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt' => commercialReceipt()],
    ]);
    EnablePackageAction::run(CapellCore::getPackage('vendor/commercial'));
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
    Http::assertNothingSent();
});

it('rejects invalid receipt contexts before enabling a paid package', function (array $overrides, bool $tamper): void {
    connectCommercialMarketplace();
    $receipt = commercialReceipt($overrides);
    if ($tamper) {
        $receipt['signature'] = 'sha256=forged';
    }

    Http::fake(['*' => Http::response(['data' => [
        'licence_status' => 'active', 'can_install' => true, 'runtime_allowed' => true,
        'signed_activation' => ['installed_receipt' => $receipt],
    ]])]);
    expect(fn () => EnablePackageAction::run(CapellCore::getPackage('vendor/commercial')))->toThrow(RuntimeException::class, 'authorisation');
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse()
        ->and($this->commercialCalls)->toBe(0);
})->with([
    'wrong domain' => [['domain' => 'someone-else.test'], false],
    'wrong instance' => [['instance_id' => 'another-instance'], false],
    'wrong package' => [['composer_name' => 'vendor/another'], false],
    'revoked receipt' => [['runtime_revoked' => true], false],
    'forged signature' => [[], true],
]);

it('requires an explicit package exemption and the local environment for development', function (string $environment, bool $exempt, bool $allowed): void {
    app()->instance('env', $environment);
    config()->set('capell.extensions.local_development', $exempt ? ['vendor/commercial'] : []);
    if ($allowed) {
        expect(Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toBe(0)
            ->and($this->commercialCalls)->toBe(1)
            ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
    } else {
        expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
    }
})->with([
    'explicit local' => ['local', true, true], 'implicit local' => ['local', false, false],
    'production ignores exemption' => ['production', true, false],
]);

it('does not override a persisted revocation even for explicit local development', function (): void {
    app()->instance('env', 'local');
    config()->set('capell.extensions.local_development', ['vendor/commercial']);
    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Disabled,
        'is_paid_marketplace_extension' => true, 'marketplace_runtime_status' => 'revoked', 'marketplace_runtime_allowed' => false,
    ]);
    expect(fn () => EnablePackageAction::run(CapellCore::getPackage('vendor/commercial')))->toThrow(RuntimeException::class, 'authorisation');
});

afterEach(function (): void {
    InstalledVersions::reload($this->commercialInstalledVersions);
    File::delete(resolve(CommercialCatalogueStore::class)->path());
    resolve(ExtensionCommercialPolicy::class)->clear();
    app()->setBasePath($this->commercialBase);
    CapellCore::clearPackages();
    File::deleteDirectory($this->commercialRoot);
});

it('refuses a copied proprietary package through the real CLI before lifecycle effects', function (): void {
    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))
        ->toThrow(RuntimeException::class, 'authorisation');
    expect($this->commercialCalls)->toBe(0)
        ->and(CapellExtension::query()->where('composer_name', 'vendor/commercial')->exists())->toBeFalse();
});

it('refuses enable when an unclassified copied package already has a disabled lifecycle row', function (): void {
    CapellExtension::query()->create(['composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Disabled]);
    expect(fn () => EnablePackageAction::run(CapellCore::getPackage('vendor/commercial')))
        ->toThrow(RuntimeException::class, 'authorisation');
    expect(CapellExtension::query()->where('composer_name', 'vendor/commercial')->sole()->status)->toBe(ExtensionStatusEnum::Disabled)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

it('does not trust an active flag without a verified receipt', function (): void {
    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Enabled,
        'marketplace_runtime_status' => 'active', 'marketplace_runtime_allowed' => true,
    ]);
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

it('applies commercial authorisation to every provider bucket in a fresh application bootstrap', function (string $status, bool $receipt, bool $runtimeAllowed, bool $allowed, string $bucket): void {
    if ($receipt) {
        connectCommercialMarketplace();
    }

    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Enabled,
        // Deliberately omit the paid flag: Composer classification is authoritative.
        'marketplace_runtime_status' => $status, 'marketplace_runtime_allowed' => $runtimeAllowed,
        'marketplace_signed_activation' => $receipt ? ['installed_receipt' => commercialReceipt()] : null,
    ]);
    $manifest = json_decode(File::get($this->commercialRoot . '/vendor/vendor/commercial/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    // All buckets must respect the commercial boundary, including metadata/install.
    $manifest['providers'] = [$bucket => [ManifestBootstrapFrontendProvider::class]];
    File::put($this->commercialRoot . '/bootstrap/cache/capell-package-manifests.php', '<?php return ' . var_export(['vendor/commercial' => $manifest], true) . ';');
    $original = app();
    $fresh = new Application($this->commercialRoot);
    try {
        foreach (['config', 'db', 'db.schema', 'events', 'cache', 'log', 'translator', 'encrypter', TrustedMarketplaceIssuerKeys::class] as $binding) {
            $fresh->instance($binding, $original->make($binding));
        }

        $fresh->instance('env', 'testing');
        $fresh->singleton(CapellCoreManager::class);
        $fresh->singleton(CapellPackageRegistry::class);
        $fresh->singleton(ExtensionLifecycleRepository::class);
        // Public runtime has no Marketplace authoring provider or private key.
        $fresh->bind(ExtensionEntitlements::class, NullExtensionEntitlements::class);
        Container::setInstance($fresh);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($fresh);
        $fresh->make(PackageRegistryBootstrapper::class)->bootstrap();
        expect($fresh->providerIsLoaded(ManifestBootstrapFrontendProvider::class))->toBe($allowed)
            ->and($fresh->bound('manifest-frontend-registered'))->toBe($allowed);
    } finally {
        Container::setInstance($original);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($original);
    }
})->with([
    'copied active flag' => ['active', false, true, false],
    'valid paid' => ['active', true, true, true],
    'ordinary non-renewal offline' => ['expired', true, true, true],
    'revoked' => ['revoked', true, false, false],
    'runtime disabled with stale paid flag' => ['active', true, false, false],
])->with(['metadata', 'install', 'runtime', 'admin', 'frontend', 'auth']);

it('preflights every bundle member before enabling any member', function (): void {
    foreach (['vendor/free-member', 'vendor/bundle'] as $name) {
        CapellCore::registerPackage($name);
        recordCommercialInstalledVersion($this->commercialRoot . '/vendor/' . $name, $name, '1.0.0.0');
        writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest(['composer_name' => $name]), $name);
    }

    $bundle = CapellCore::getPackage('vendor/bundle');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/free-member', 'vendor/commercial'];

    expect(fn () => EnablePackageAction::run($bundle))->toThrow(RuntimeException::class, __('capell-core::extensions.authorisation.required', ['package' => 'vendor/commercial']));
    expect(CapellCore::isPackageEnabled('vendor/free-member'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

it('does not let activation-pending CLI retries bypass commercial authorisation', function (): void {
    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Enabled,
        'metadata' => [ExtensionLifecycleRepository::ACTIVATION_PENDING_KEY => true],
    ]);
    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
    expect($this->commercialCalls)->toBe(0);
});

it('refuses a nested paid bundle leaf before running any install lifecycle', function (): void {
    foreach (['vendor/outer', 'vendor/inner'] as $name) {
        CapellCore::registerPackage($name);
        CapellCore::getPackage($name)->kind = 'bundle';
        recordCommercialInstalledVersion($this->commercialRoot . '/vendor/' . $name, $name, '1.0.0.0');
        writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest(['composer_name' => $name]), $name);
    }

    CapellCore::getPackage('vendor/outer')->requirements = ['vendor/inner'];
    CapellCore::getPackage('vendor/inner')->requirements = ['vendor/commercial'];
    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/outer', '--no-interaction' => true]))
        ->toThrow(RuntimeException::class, __('capell-core::extensions.authorisation.required', ['package' => 'vendor/commercial']));
    expect($this->commercialCalls)->toBe(0)
        ->and(CapellExtension::query()->whereIn('composer_name', ['vendor/outer', 'vendor/inner', 'vendor/commercial'])->count())->toBe(0);
});

it('does not allow forced installation state to authorise a commercial package without a lifecycle row', function (): void {
    CapellCore::forcePackageInstalled('vendor/commercial');
    expect(CapellCore::isPackageInstalled('vendor/commercial'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

it('fails closed without a licence manifest even when the catalogue, Composer and database say free', function (): void {
    expect(resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(false)))->toBeTrue();
    $file = $this->commercialRoot . '/vendor/composer/installed.json';
    $metadata = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
    $metadata['packages'][0]['license'] = ['MIT'];
    File::put($file, json_encode($metadata, JSON_THROW_ON_ERROR));
    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Disabled, 'is_paid_marketplace_extension' => false,
    ]);
    expect(fn () => EnablePackageAction::run(CapellCore::getPackage('vendor/commercial')))->toThrow(RuntimeException::class, 'authorisation');
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

it('keeps the legacy behaviour while no issuer key is provisioned', function (): void {
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(keys: []));
    expect(Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toBe(0)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
});

it('rejects a receipt minted with the installation held HMAC secret', function (): void {
    connectCommercialMarketplace();
    $receipt = commercialReceipt();
    $receipt['receipt_version'] = 1;
    unset($receipt['signature']);
    $receipt = resolve(MarketplacePayloadSigner::class)->signedPayload($receipt, 'commercial-secret');
    CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Enabled,
        'marketplace_runtime_status' => 'active', 'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt' => $receipt],
    ]);
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function commercialCatalogue(bool $paid, int $sequence = 1_800_000_000, array $overrides = []): array
{
    commercialReceipt();
    $catalogue = array_replace([
        'catalogue_version' => 1, 'purpose' => 'capell-extension-commercial-classification',
        'sequence' => $sequence, 'issued_at' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addDays(29)->toIso8601String(),
        'packages' => [['composer_name' => 'vendor/commercial', 'is_paid' => $paid]],
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
    ], $overrides);
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
    $catalogue['signature']['value'] = base64_encode(sodium_crypto_sign_detached(CanonicalizeProjectBuildManifestSigningInputAction::run($catalogue), sodium_crypto_sign_secretkey($keys)));

    return $catalogue;
}

it('uses issuer classification rather than an edited Composer licence for recognised paid packages', function (bool $tamper): void {
    $file = $this->commercialRoot . '/vendor/composer/installed.json';
    $metadata = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
    $metadata['packages'][0]['license'] = ['MIT'];
    File::put($file, json_encode($metadata, JSON_THROW_ON_ERROR));
    $catalogue = commercialCatalogue(true);
    if ($tamper) {
        $catalogue['packages'][0]['is_paid'] = false;
    }

    expect(resolve(CommercialCatalogueStore::class)->accept($catalogue))->toBe(! $tamper);
    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
    expect($this->commercialCalls)->toBe(0);
})->with(['authoritative paid' => false, 'tampered classification fails closed' => true]);

it('installs and enables issuer-signed free packages offline without a catalogue or account', function (string $name, bool $hasInstallPath): void {
    if ($name !== 'vendor/commercial') {
        installCommercialFixture($this->commercialRoot, $name);
    }

    if (! $hasInstallPath) {
        recordCommercialInstalledVersion(null, $name, '1.0.0.0');
    }

    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest([
        'composer_name' => strtoupper($name),
        'issued_at' => now()->subYears(5)->toIso8601String(),
    ]), $name);
    Http::preventStrayRequests();
    expect(Artisan::call('capell:extension-install', ['extension' => $name, '--no-interaction' => true]))->toBe(0)
        ->and($this->commercialCalls)->toBe(1)
        ->and(CapellCore::isPackageEnabled($name))->toBeTrue();
    expect(CapellExtension::query()->where('composer_name', $name)->sole()->status)->toBe(ExtensionStatusEnum::Enabled);
    Http::assertNothingSent();
})->with([
    'third-party package' => ['vendor/commercial', true],
    'Capell-vendor package' => ['capell-app/licence-fixture', true],
    'Composer version without an install path' => ['vendor/commercial', false],
]);

it('registers only the signed-free extension through Laravel discovery without a database binding', function (?bool $paid, bool $discovered, bool $hasCapellManifest = true): void {
    if ($paid !== null) {
        writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest(['is_paid' => $paid]));
    }

    if (! $hasCapellManifest) {
        File::delete($this->commercialRoot . '/vendor/vendor/commercial/capell.json');
    }

    expect(discoverCommercialLaravelProvider($this->commercialRoot))->toBe($discovered);
})->with([
    'unsigned' => [null, false],
    'signed paid' => [true, false],
    'signed paid identified by its licence manifest alone' => [true, false, false],
    'signed free' => [false, true],
]);

function discoverCommercialLaravelProvider(string $root): bool
{
    $original = app();
    $fresh = new Application($root);
    $fresh->useStoragePath($original->storagePath());
    $fresh->instance(TrustedMarketplaceIssuerKeys::class, $original->make(TrustedMarketplaceIssuerKeys::class));
    $fresh->instance('config', new Repository);
    $fresh->instance('env', 'production');
    try {
        Container::setInstance($fresh);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($fresh);

        $files = new Filesystem;
        $manifest = new RuntimeRolePackageManifest(
            $files,
            $root,
            $root . '/bootstrap/cache/runtime/packages.php',
            $root . '/bootstrap/cache/packages.php',
            RuntimeRole::Combined,
            new RuntimeRoleProviderPolicy,
        );
        expect($fresh->bound('db'))->toBeFalse();
        new ProviderRepository($fresh, $files, $root . '/bootstrap/cache/discovery-services.php')->load($manifest->providers());

        return $fresh->bound('manifest-frontend-registered');
    } finally {
        Container::setInstance($original);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($original);
    }
}

it('rediscovers a signed-free provider after a catalogue that raised its classification expires', function (): void {
    $root = $this->commercialRoot;
    writeCommercialLicenceManifest($root, commercialLicenceManifest());
    expect(resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(true)))->toBeTrue();

    // Keep a second provider so the first boot writes a non-empty role cache.
    $file = $root . '/vendor/composer/installed.json';
    $installed = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
    $installed['packages'][] = [
        'name' => 'vendor/laravel-dependency',
        'extra' => ['laravel' => ['providers' => [FilesystemServiceProvider::class]]],
    ];
    File::put($file, json_encode($installed, JSON_THROW_ON_ERROR));
    expect(discoverCommercialLaravelProvider($root))->toBeFalse()
        ->and(File::exists($root . '/bootstrap/cache/runtime/packages.php'))->toBeTrue();

    $this->travel(30)->days();
    expect(discoverCommercialLaravelProvider($root))->toBeTrue();
});

it('requires a receipt to enable a package with a paid manifest', function (): void {
    Http::preventStrayRequests();
    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest(['is_paid' => true]));
    $extension = CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Disabled,
        'is_paid_marketplace_extension' => false,
    ]);

    expect(fn () => EnablePackageAction::run(CapellCore::getPackage('vendor/commercial')))->toThrow(RuntimeException::class, 'authorisation');
    expect($extension->refresh()->status)->toBe(ExtensionStatusEnum::Disabled);

    connectCommercialMarketplace();
    $extension->update([
        'marketplace_runtime_status' => 'active', 'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt_v2' => commercialReceipt()],
    ]);
    EnablePackageAction::run(CapellCore::getPackage('vendor/commercial'));

    expect($extension->refresh()->status)->toBe(ExtensionStatusEnum::Enabled)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
    Http::assertNothingSent();
});

it('requires authorisation when a signed licence manifest does not match the installed package', function (array $overrides, array $edits, ?string $version): void {
    recordCommercialInstalledVersion($this->commercialRoot . '/vendor/vendor/commercial', 'vendor/commercial', $version);
    writeCommercialLicenceManifest($this->commercialRoot, array_replace(commercialLicenceManifest($overrides), $edits));

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/commercial'))->toBeTrue();
})->with([
    'copied from another package' => [['composer_name' => 'vendor/another'], [], '1.0.0.0'],
    'version outside range' => [['version_constraint' => '^2.0'], [], '1.0.0.0'],
    'invalid constraint' => [['version_constraint' => 'invalid range'], [], '1.0.0.0'],
    'dev version even with a wildcard' => [['version_constraint' => '*'], [], 'dev-main'],
    'missing installed version' => [[], [], null],
    'unparsable version' => [[], [], 'invalid version'],
    'wrong manifest version' => [['manifest_version' => '1'], [], '1.0.0.0'],
    'non-boolean classification' => [['is_paid' => 0], [], '1.0.0.0'],
    'unparsable timestamp' => [['issued_at' => 'invalid date'], [], '1.0.0.0'],
    'future issuance' => [['issued_at' => now()->addHour()->toIso8601String()], [], '1.0.0.0'],
    'unsigned' => [[], ['signature' => null], '1.0.0.0'],
    'forged signature' => [[], ['signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => base64_encode(str_repeat('x', 64))]], '1.0.0.0'],
    'edited paid flag' => [['is_paid' => true], ['is_paid' => false], '1.0.0.0'],
    'edited version constraint' => [['version_constraint' => '^2.0'], ['version_constraint' => '^1.0'], '1.0.0.0'],
    'edited composer name' => [['composer_name' => 'vendor/another'], ['composer_name' => 'vendor/commercial'], '1.0.0.0'],
]);

it('rejects signed payloads from other purposes at the licence manifest boundary', function (string $source): void {
    $manifestFields = ['manifest_version' => 1, 'version_constraint' => '^1.0', 'is_paid' => false];
    // Supply the licence fields before signing so purpose is the distinguishing condition.
    $payload = $source === 'receipt'
        ? commercialReceipt($manifestFields)
        : commercialCatalogue(false, overrides: [...$manifestFields, 'composer_name' => 'vendor/commercial']);
    writeCommercialLicenceManifest($this->commercialRoot, $payload);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/commercial'))->toBeTrue();
})->with(['receipt', 'catalogue']);

it('requires authorisation when reading a licence manifest would exceed its decoding bounds', function (string $limit): void {
    $metadata = 'leaf';
    for ($depth = 0; $depth < 20; $depth++) {
        $metadata = [$metadata];
    }

    $payload = $limit === 'depth' ? commercialLicenceManifest(['metadata' => $metadata]) : commercialLicenceManifest();
    $file = $this->commercialRoot . '/vendor/vendor/commercial/capell-licence.json';
    writeCommercialLicenceManifest($this->commercialRoot, $payload);
    if ($limit === 'size') {
        File::append($file, str_repeat(' ', 64 * 1024));
    }

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/commercial'))->toBeTrue();
})->with(['size', 'depth']);

it('requires authorisation for a locally recorded paid package even with a free manifest', function (): void {
    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest());
    $extension = CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Disabled,
        'is_paid_marketplace_extension' => true, 'marketplace_runtime_allowed' => true,
    ]);

    expect(fn () => EnablePackageAction::run(CapellCore::getPackage('vendor/commercial')))->toThrow(RuntimeException::class, 'authorisation');
    expect($extension->refresh()->status)->toBe(ExtensionStatusEnum::Disabled);
});

function commercialExtensionWith(?array $activation, string $status = 'active'): CapellExtension
{
    return CapellExtension::query()->create([
        'composer_name' => 'vendor/commercial', 'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true, 'marketplace_runtime_status' => $status, 'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => $activation,
    ]);
}

it('verifies only purpose bound version 2 receipts inside a bounded runtime window', function (array $overrides, string $location, bool $valid): void {
    connectCommercialMarketplace();
    commercialExtensionWith([$location => commercialReceipt($overrides)]);
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBe($valid);
})->with([
    'v2 location' => [[], 'installed_receipt_v2', true],
    'version 2 at the legacy location' => [[], 'installed_receipt', true],
    'version 1 at the legacy location' => [['receipt_version' => 1], 'installed_receipt', false],
    'missing purpose' => [['purpose' => null], 'installed_receipt_v2', false],
    'catalogue purpose reused' => [['purpose' => 'capell-extension-commercial-classification'], 'installed_receipt_v2', false],
    'runtime window ended' => [['issued_at' => now()->subDays(25)->toIso8601String(), 'runtime_valid_until' => now()->subDay()->toIso8601String()], 'installed_receipt_v2', false],
    'window longer than Core honours' => [['runtime_valid_until' => now()->addDays(40)->toIso8601String()], 'installed_receipt_v2', false],
    'no runtime window' => [['runtime_valid_until' => null], 'installed_receipt_v2', false],
    'issued in the future' => [['issued_at' => now()->addHour()->toIso8601String()], 'installed_receipt_v2', false],
]);

it('keeps the highest catalogue sequence and refuses an older replay', function (): void {
    $store = resolve(CommercialCatalogueStore::class);
    expect($store->accept(commercialCatalogue(true, 1_800_000_100)))->toBeTrue()
        ->and($store->accept(commercialCatalogue(false, 1_800_000_000)))->toBeFalse()
        ->and($store->accept(commercialCatalogue(false, 1_800_000_100)))->toBeFalse();
    $store->clear();
    expect($store->classification('vendor/commercial'))->toBeTrue()
        ->and($store->accept(commercialCatalogue(false, 1_800_000_200)))->toBeTrue()
        ->and($store->classification('vendor/commercial'))->toBeFalse();
});

it('stops honouring a catalogue once its signed validity window ends', function (): void {
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);
    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest());
    expect($store->accept(commercialCatalogue(true)))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/commercial'))->toBeTrue();
    $this->travel(30)->days();
    expect($store->current())->toBeNull()
        ->and($policy->requiresAuthorisation('vendor/commercial'))->toBeFalse();
});

it('does not let a local development exemption cover an issuer classified paid package', function (string $classification): void {
    app()->instance('env', 'local');
    config()->set('capell.extensions.local_development', ['vendor/commercial']);
    if ($classification === 'manifest') {
        writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest(['is_paid' => true]));
    } else {
        writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest());
        expect(resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(true)))->toBeTrue();
    }

    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
    expect($this->commercialCalls)->toBe(0);
})->with(['manifest', 'catalogue']);

it('refuses a catalogue without a sequence or with a tampered body', function (): void {
    $catalogue = commercialCatalogue(false);
    $unsequenced = $catalogue;
    unset($unsequenced['sequence']);
    $catalogue['packages'][0]['composer_name'] = 'vendor/another';
    expect(resolve(CommercialCatalogueStore::class)->accept($unsequenced))->toBeFalse()
        ->and(resolve(CommercialCatalogueStore::class)->accept($catalogue))->toBeFalse()
        ->and(resolve(CommercialCatalogueStore::class)->current())->toBeNull();
});

it('renews receipts and the catalogue from the heartbeat and applies runtime revocation', function (): void {
    connectCommercialMarketplace();
    $old = commercialReceipt(['issued_at' => now()->subDays(25)->toIso8601String(), 'runtime_valid_until' => now()->subDay()->toIso8601String()]);
    commercialExtensionWith(['installed_receipt_v2' => $old]);
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();

    $secret = 'commercial-secret';
    $heartbeats = Http::sequence();
    Http::fake(['https://marketplace.test/instances/heartbeat' => $heartbeats]);
    $heartbeats->push(['data' => [
        'instance_id' => 'ddda8b4c-3850-4d20-9cf4-460c9364c413', 'alerts' => [],
        'installed_receipts' => [commercialReceipt(), commercialReceipt(['composer_name' => 'vendor/missing'])],
        'commercial_catalogue' => commercialCatalogue(true),
    ]]);
    SendExtensionHealthReportAction::run(source: 'heartbeat', instanceId: 'ddda8b4c-3850-4d20-9cf4-460c9364c413', signingSecret: $secret);
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue()
        ->and(resolve(CommercialCatalogueStore::class)->classification('vendor/commercial'))->toBeTrue();

    $alert = ['alert_id' => 'revoke-commercial', 'severity' => 'critical', 'category' => 'licence', 'title' => 'Revoked',
        'message' => 'Licence revoked.', 'composer_name' => 'vendor/commercial', 'runtime_disabled' => true,
        'protected_actions_blocked' => true, 'issued_at' => now()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String()];
    $payload = $alert;
    ksort($payload);
    $alert['signature'] = 'sha256=' . hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $secret);
    $heartbeats->push(['data' => [
        'instance_id' => 'ddda8b4c-3850-4d20-9cf4-460c9364c413', 'alerts' => [$alert],
        // A receipt signed before the revocation cannot reinstate the package.
        'installed_receipts' => [commercialReceipt()],
    ]]);
    SendExtensionHealthReportAction::run(source: 'heartbeat', instanceId: 'ddda8b4c-3850-4d20-9cf4-460c9364c413', signingSecret: $secret);
    $extension = CapellExtension::query()->where('composer_name', 'vendor/commercial')->sole();
    expect($extension->marketplace_runtime_status)->toBe('revoked')
        ->and($extension->marketplace_runtime_allowed)->toBeFalse()
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();

    $this->travel(2)->minutes();
    ApplyMarketplaceIssuanceAction::run(receipts: [commercialReceipt(['issued_at' => now()->toIso8601String()])], catalogue: null);
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
});

it('ignores an unsigned revocation alert', function (): void {
    connectCommercialMarketplace();
    commercialExtensionWith(['installed_receipt_v2' => commercialReceipt()]);
    Http::fake(['https://marketplace.test/instances/heartbeat' => Http::response(['data' => [
        'alerts' => [['alert_id' => 'forged', 'severity' => 'critical', 'category' => 'licence', 'title' => 'x', 'message' => 'x',
            'composer_name' => 'vendor/commercial', 'runtime_disabled' => true, 'signature' => 'sha256=forged']],
    ]])]);
    SendExtensionHealthReportAction::run(source: 'heartbeat', instanceId: 'ddda8b4c-3850-4d20-9cf4-460c9364c413', signingSecret: 'commercial-secret');
    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
});

it('imports an offline receipt bundle only for this installation', function (string $instance, int $exit, bool $enabled): void {
    connectCommercialMarketplace();
    commercialExtensionWith(null);
    $bundle = $this->commercialRoot . '/bundle.json';
    File::put($bundle, json_encode([
        'bundle_version' => 1, 'instance_id' => $instance, 'issued_at' => now()->toIso8601String(),
        'installed_receipts' => [commercialReceipt()], 'commercial_catalogue' => commercialCatalogue(true),
    ], JSON_THROW_ON_ERROR));
    expect(Artisan::call('capell:marketplace:import-receipts', ['bundle' => $bundle]))->toBe($exit)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBe($enabled);
})->with([
    'this installation' => ['ddda8b4c-3850-4d20-9cf4-460c9364c413', 0, true],
    'another installation' => ['another-instance', 1, false],
]);

it('re-reads a catalogue another worker stored after the per-operation flush', function (): void {
    $store = resolve(CommercialCatalogueStore::class);
    expect($store->classification('vendor/commercial'))->toBeNull();
    // Another worker accepts a newer catalogue through its own store instance.
    expect(new CommercialCatalogueStore()->accept(commercialCatalogue(false)))->toBeTrue()
        ->and($store->classification('vendor/commercial'))->toBeNull();
    resolve(CapellCoreManager::class)->flushOctaneState();
    expect($store->classification('vendor/commercial'))->toBeFalse();
});

it('reclassifies a package whose licence manifest changes after the per-operation flush', function (): void {
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/commercial'))->toBeTrue();

    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest());
    expect($policy->requiresAuthorisation('vendor/commercial'))->toBeTrue();
    resolve(CapellCoreManager::class)->flushOctaneState();
    expect($policy->requiresAuthorisation('vendor/commercial'))->toBeFalse();

    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest(['is_paid' => true]));
    expect($policy->requiresAuthorisation('vendor/commercial'))->toBeFalse();
    resolve(CapellCoreManager::class)->flushOctaneState();
    expect($policy->requiresAuthorisation('vendor/commercial'))->toBeTrue();
});

/** Pins the given issuer keys as if they were the keys committed with Core. */
function pinCommercialIssuerKeys(object $test, array $keys): void
{
    $path = $test->commercialRoot . '/issuer-keys-' . bin2hex(random_bytes(4)) . '.json';
    File::put($path, json_encode(['keys' => (object) $keys], JSON_THROW_ON_ERROR));
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys($path));
}

/** The base64 public key commercialReceipt() and commercialCatalogue() sign with. */
function commercialIssuerPublicKey(): string
{
    return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32))));
}

it('rejects receipts, catalogues and licence manifests signed with an owner configured key in production', function (): void {
    connectCommercialMarketplace();
    $receipt = commercialReceipt();
    $catalogue = commercialCatalogue(false);
    writeCommercialLicenceManifest($this->commercialRoot, commercialLicenceManifest());
    // Core carries the genuine issuer key; the owner configures the key they signed with.
    $genuine = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\x09", 32)));
    pinCommercialIssuerKeys($this, ['capell-issuer' => base64_encode($genuine)]);
    config()->set('capell.extensions.marketplace_receipt_public_keys', ['test-issuer' => commercialIssuerPublicKey()]);
    commercialExtensionWith(['installed_receipt_v2' => $receipt]);
    app()->instance('env', 'production');

    expect(resolve(ExtensionCommercialPolicy::class)->enforcing())->toBeTrue()
        ->and(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/commercial'))->toBeTrue()
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse()
        ->and(resolve(CommercialCatalogueStore::class)->accept($catalogue))->toBeFalse();
});

it('takes the issuer keys only from Core, never from configuration or the environment', function (string $environment): void {
    pinCommercialIssuerKeys($this, []);
    config()->set('capell.extensions.marketplace_receipt_public_keys', ['test-issuer' => commercialIssuerPublicKey()]);
    app()->instance('env', $environment);

    expect(resolve(TrustedMarketplaceIssuerKeys::class)->all())->toBe([])
        ->and(resolve(ExtensionCommercialPolicy::class)->enforcing())->toBeFalse();
})->with(['production', 'local', 'staging']);

it('verifies receipts and catalogues against the pinned issuer key in production', function (): void {
    connectCommercialMarketplace();
    $receipt = commercialReceipt();
    $catalogue = commercialCatalogue(true);
    pinCommercialIssuerKeys($this, ['test-issuer' => commercialIssuerPublicKey()]);
    commercialExtensionWith(['installed_receipt_v2' => $receipt]);
    app()->instance('env', 'production');

    expect(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue()
        ->and(resolve(CommercialCatalogueStore::class)->accept($catalogue))->toBeTrue();
});
