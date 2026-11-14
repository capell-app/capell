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
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Tests\Support\ManifestBootstrapFrontendProvider;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceExtensionEntitlements;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->commercialBase = app()->basePath();
    $this->commercialRoot = sys_get_temp_dir() . '/capell-commercial-' . bin2hex(random_bytes(8));
    $root = $this->commercialRoot;
    File::ensureDirectoryExists($root . '/vendor/composer');
    File::ensureDirectoryExists($root . '/vendor/vendor/commercial/src');
    File::ensureDirectoryExists($root . '/bootstrap/cache');
    $manifest = capellManifestV3Array(
        name: 'vendor/commercial',
        surfaces: ['frontend'],
        providers: ['frontend' => [ManifestBootstrapFrontendProvider::class]],
    );
    // A copied manifest's free claim cannot lower Composer's commercial requirement.
    $manifest['product']['tier'] = 'free';
    $manifest['version'] = '1.0.0';
    $manifest['commands']['install'] = 'test:commercial-install';
    $manifest['providers']['frontend'] = [];
    $composer = [
        'name' => 'vendor/commercial', 'version' => '1.0.0', 'license' => ['proprietary'],
        'type' => 'library', 'install-path' => '../vendor/commercial', 'autoload' => ['psr-4' => ['Vendor\\Commercial\\' => 'src/']],
        'extra' => ['laravel' => ['providers' => [ManifestBootstrapFrontendProvider::class]]],
    ];
    File::put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$composer]], JSON_THROW_ON_ERROR));
    File::put($root . '/vendor/vendor/commercial/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    File::put($root . '/vendor/vendor/commercial/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    File::put($root . '/bootstrap/cache/capell-package-manifests.php', '<?php return ' . var_export(['vendor/commercial' => $manifest], true) . ';');
    app()->setBasePath($root);
    config()->set('app.url', 'https://commercial.test');
    config()->set('capell.extensions.verification.strict', true);
    File::delete(resolve(CommercialCatalogueStore::class)->path());
    resolve(CommercialCatalogueStore::class)->clear();
    // Provisioning the issuer key switches enforcement on; most tests run enforced.
    commercialReceipt();
    CapellCore::clearPackages();
    CapellCore::registerManifestPackage(resolve(ManifestLoader::class)->load($root . '/vendor/vendor/commercial/capell.json'));
    $this->commercialCalls = 0;
    $test = $this;
    Artisan::command('test:commercial-install', function () use ($test): int {
        $test->commercialCalls++;

        return 0;
    });
});

/** @return array<string, mixed> */
function commercialReceipt(array $overrides = []): array
{
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
    config()->set('capell.extensions.marketplace_receipt_public_keys', ['test-issuer' => base64_encode(sodium_crypto_sign_publickey($keys))]);
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
    File::delete(resolve(CommercialCatalogueStore::class)->path());
    resolve(CommercialCatalogueStore::class)->clear();
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

it('keeps third party packages the signed catalogue does not list installable', function (): void {
    expect(resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(null)))->toBeTrue();
    expect(Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toBe(0)
        ->and($this->commercialCalls)->toBe(1)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
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
        foreach (['config', 'db', 'db.schema', 'events', 'cache', 'log', 'translator', 'encrypter'] as $binding) {
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
    CapellCore::registerPackage('vendor/free-member');
    CapellCore::registerPackage('vendor/bundle');
    $bundle = CapellCore::getPackage('vendor/bundle');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/free-member', 'vendor/commercial'];

    expect(fn () => EnablePackageAction::run($bundle))->toThrow(RuntimeException::class, 'authorisation');
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
    }

    CapellCore::getPackage('vendor/outer')->requirements = ['vendor/inner'];
    CapellCore::getPackage('vendor/inner')->requirements = ['vendor/commercial'];
    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/outer', '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
    expect($this->commercialCalls)->toBe(0)
        ->and(CapellExtension::query()->whereIn('composer_name', ['vendor/outer', 'vendor/inner', 'vendor/commercial'])->count())->toBe(0);
});

it('does not allow forced installation state to authorise a commercial package without a lifecycle row', function (): void {
    CapellCore::forcePackageInstalled('vendor/commercial');
    expect(CapellCore::isPackageInstalled('vendor/commercial'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeFalse();
});

it('fails closed without a signed catalogue whatever Composer and the database claim', function (): void {
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
    config()->set('capell.extensions.marketplace_receipt_public_keys', []);
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

/** @return array<string, mixed> */
function commercialCatalogue(?bool $paid, int $sequence = 1_800_000_000): array
{
    commercialReceipt();
    $catalogue = [
        'catalogue_version' => 1, 'purpose' => 'capell-extension-commercial-classification',
        'sequence' => $sequence, 'issued_at' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addDays(29)->toIso8601String(),
        'packages' => $paid === null ? [['composer_name' => 'vendor/other', 'is_paid' => true]] : [['composer_name' => 'vendor/commercial', 'is_paid' => $paid]],
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
    ];
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

it('installs an issuer classified proprietary free package offline without an account or paid entitlement', function (): void {
    expect(resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(false)))->toBeTrue();
    Http::preventStrayRequests();
    expect(Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toBe(0)
        ->and($this->commercialCalls)->toBe(1)
        ->and(CapellCore::isPackageEnabled('vendor/commercial'))->toBeTrue();
    Http::assertNothingSent();
});

it('defers commercial Laravel auto discovery to the gated Capell provider loader', function (bool $free): void {
    if ($free) {
        resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(false));
    }

    $root = $this->commercialRoot;
    $manifest = new RuntimeRolePackageManifest(
        new Filesystem,
        $root,
        $root . '/bootstrap/cache/runtime/packages.php',
        $root . '/bootstrap/cache/packages.php',
        RuntimeRole::Combined,
        new RuntimeRoleProviderPolicy,
    );
    expect(in_array(ManifestBootstrapFrontendProvider::class, $manifest->providers(), true))->toBe($free);
})->with(['commercial deferred' => false, 'genuinely free discovered' => true]);

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
    expect($store->accept(commercialCatalogue(false)))->toBeTrue();
    $this->travel(30)->days();
    $store->clear();
    // A restored older catalogue counts only inside its window; afterwards Core fails closed.
    expect($store->current())->toBeNull()
        ->and(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/commercial'))->toBeTrue();
});

it('does not let a local development exemption cover an issuer classified paid package', function (): void {
    app()->instance('env', 'local');
    config()->set('capell.extensions.local_development', ['vendor/commercial']);
    resolve(CommercialCatalogueStore::class)->accept(commercialCatalogue(true));
    expect(fn () => Artisan::call('capell:extension-install', ['extension' => 'vendor/commercial', '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
});

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
