<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Tests\Support\DiscoveredFreeInstallFixtureProvider;
use Capell\Core\Tests\Support\ManifestBootstrapFrontendProvider;
use Capell\Core\Tests\Support\ProtectedReleaseBootstrapFixtureProvider;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\ProviderRepository;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\View\ViewServiceProvider;

const PACKAGE_CLASS_REFERENCE = 'fixture-release-reference';
const PACKAGE_CLASS_INSTANCE = 'ddda8b4c-3850-4d20-9cf4-460c9364c413';

beforeEach(function (): void {
    $this->packageClassRoot = sys_get_temp_dir() . '/capell-package-class-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->packageClassRoot . '/vendor/composer');
    $files->ensureDirectoryExists($this->packageClassRoot . '/bootstrap/cache');

    $this->packageClassInstalled = InstalledVersions::getAllRawData()[0];
    config()->set('app.url', 'https://package-class.test');
    config()->set('capell.extensions.marketplace_instance_id', PACKAGE_CLASS_INSTANCE);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    InstalledVersions::reload($this->packageClassInstalled);
    new Filesystem()->deleteDirectory($this->packageClassRoot);
});

function packageClassKeypair(): string
{
    return sodium_crypto_sign_seed_keypair(str_repeat("\x05", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

function packageClassKeys(bool $pinned): TrustedMarketplaceIssuerKeys
{
    return new TrustedMarketplaceIssuerKeys(keys: $pinned
        ? ['fixture-issuer' => base64_encode(sodium_crypto_sign_publickey(packageClassKeypair()))]
        : []);
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function signPackageClassPayload(array $fields): array
{
    $fields['signature'] = ['algorithm' => 'ed25519', 'keyId' => 'fixture-issuer', 'value' => ''];
    $fields['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
        CanonicalizeProjectBuildManifestSigningInputAction::run($fields),
        sodium_crypto_sign_secretkey(packageClassKeypair()),
    ));

    return $fields;
}

/** @return array<string, mixed> */
function packageClassReceipt(string $name): array
{
    return signPackageClassPayload([
        'receipt_version' => 2, 'purpose' => 'capell-installed-receipt', 'receipt_id' => 'fixture-receipt',
        'composer_name' => $name, 'package_version' => '1.0.0', 'package_identity' => 'fixture-paid-release',
        'instance_id' => PACKAGE_CLASS_INSTANCE, 'domain' => 'package-class.test',
        'issued_at' => now()->subDay()->toIso8601String(), 'runtime_valid_until' => now()->addDay()->toIso8601String(),
        'perpetual_installed_runtime' => true, 'runtime_revoked' => false,
    ]);
}

/**
 * @param  class-string  $laravelProvider
 * @param  array<string, mixed>  $manifest
 */
function writePackageClassFixture(string $root, string $name, string $laravelProvider, array $manifest, string $proof): void
{
    $files = new Filesystem;
    $path = $root . '/vendor/' . $name;
    $files->ensureDirectoryExists($path);
    $composer = [
        'name' => $name,
        'install-path' => '../' . $name,
        'extra' => ['laravel' => ['providers' => [$laravelProvider]]],
    ];
    $files->put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$composer]], JSON_THROW_ON_ERROR));
    $files->put($path . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    $files->put($path . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $files->put($root . '/bootstrap/cache/capell-package-manifests.php', '<?php return ' . var_export([$name => $manifest], true) . ';');

    $installed = InstalledVersions::getAllRawData()[0];
    $installed['versions'][$name] = [
        'version' => '1.0.0.0', 'pretty_version' => '1.0.0', 'reference' => PACKAGE_CLASS_REFERENCE,
        'install_path' => $path, 'type' => 'library', 'aliases' => [], 'dev_requirement' => false,
    ];
    InstalledVersions::reload($installed);

    if (! in_array($proof, ['absent', 'catalogue paid'], true)) {
        $licence = signPackageClassPayload([
            'manifest_version' => 1,
            'purpose' => 'capell-extension-licence-manifest',
            'composer_name' => $proof === 'renamed' ? 'capell-app/original-paid' : $name,
            'is_paid' => in_array($proof, ['paid', 'renamed'], true),
            'version' => $proof === 'wrong version' ? '1.1.0' : '1.0.0',
            'source_reference' => $proof === 'wrong reference' ? 'earlier-release-reference' : PACKAGE_CLASS_REFERENCE,
            'issued_at' => now()->subDay()->toIso8601String(),
        ]);
        if ($proof === 'forged') {
            $licence['is_paid'] = ! $licence['is_paid'];
        }

        $files->put($path . '/capell-licence.json', json_encode($licence, JSON_THROW_ON_ERROR));
    }
}

function packageClassDiscovery(Application $app, RuntimeRole $role = RuntimeRole::Combined): RuntimeRolePackageManifest
{
    return new RuntimeRolePackageManifest(
        new Filesystem,
        $app->basePath(),
        $app->bootstrapPath('cache/' . $role->value . '/packages.php'),
        $app->bootstrapPath('cache/packages.php'),
        $role,
        new RuntimeRoleProviderPolicy,
    );
}

/** @param Closure(Application, Application): void $run */
function withFreshPackageClassApplication(string $root, bool $pinned, Closure $run): void
{
    $original = app();
    $modelResolver = Model::getConnectionResolver();
    $modelEvents = Model::getEventDispatcher();
    $fresh = new Application($root);

    try {
        foreach (['config', 'events', 'cache', 'log', 'translator', 'encrypter', HttpFactory::class] as $binding) {
            $fresh->instance($binding, $original->make($binding));
        }

        $fresh->instance('env', 'testing');
        $fresh->instance(TrustedMarketplaceIssuerKeys::class, packageClassKeys($pinned));
        $fresh->bind(ExtensionEntitlements::class, NullExtensionEntitlements::class);
        Container::setInstance($fresh);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($fresh);
        $run($fresh, $original);
    } finally {
        Model::setConnectionResolver($modelResolver);
        Model::setEventDispatcher($modelEvents);
        Container::setInstance($original);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($original);
    }
}

function loadPackageClassProviders(Application $app): void
{
    $app->singleton(CapellCoreManager::class);
    $app->singleton(CapellPackageRegistry::class);
    $app->singleton(ExtensionLifecycleRepository::class);
    $app->make(PackageRegistryBootstrapper::class)->bootstrap();
}

it('keeps Composer discovery independent of licence proof and gates Capell providers at cold boot', function (
    string $name,
    string $proof,
    bool $requiresReceipt,
    bool $pinned,
): void {
    $manifest = capellManifestV3Array(name: $name, providers: ['metadata' => [FilesystemServiceProvider::class]]);
    writePackageClassFixture($this->packageClassRoot, $name, HashServiceProvider::class, $manifest, $proof);
    withFreshPackageClassApplication($this->packageClassRoot, $pinned, function (Application $fresh) use ($name, $proof, $requiresReceipt, $pinned): void {
        $catalogue = $fresh->make(CommercialCatalogueStore::class);
        expect($catalogue->current())->toBeNull();
        if ($pinned && $proof === 'catalogue paid') {
            expect($catalogue->accept(signPackageClassPayload([
                'catalogue_version' => 1, 'purpose' => CommercialCatalogueStore::PURPOSE, 'sequence' => 1,
                'issued_at' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String(),
                'packages' => [['composer_name' => $name, 'is_paid' => true]],
            ])))->toBeTrue();
        }

        expect($fresh->bound('db'))->toBeFalse()
            ->and(packageClassDiscovery($fresh)->providers())->toBe([HashServiceProvider::class])
            ->and($fresh->make(ExtensionCommercialPolicy::class)->requiresAuthorisation($name))->toBe($pinned && $requiresReceipt);

        loadPackageClassProviders($fresh);
        expect($fresh->providerIsLoaded(FilesystemServiceProvider::class))->toBe(! $pinned || ! $requiresReceipt);
    });
    Http::assertNothingSent();
})->with([
    'Capell vendor with a signed free release and a different capell.json version' => ['capell-app/free-fixture', 'free', false],
    'Capell vendor with no proof' => ['capell-app/free-fixture', 'absent', true],
    'Capell vendor with an edited signed payload' => ['capell-app/free-fixture', 'forged', true],
    'Capell vendor with proof for another version' => ['capell-app/free-fixture', 'wrong version', true],
    'Capell vendor with proof for another reference' => ['capell-app/free-fixture', 'wrong reference', true],
    'independent package without proof' => ['vendor/free-fixture', 'absent', false],
    'independent package with an edited signed payload' => ['vendor/free-fixture', 'forged', true],
    'exact foundation package' => ['capell-app/core', 'absent', false],
    'foundation name with positive paid catalogue evidence' => ['capell-app/core', 'catalogue paid', true],
    'foundation name with invalid proof' => ['capell-app/core', 'forged', true],
])->with(['pinned issuer' => true, 'empty key set' => false]);

it('loads an offline free release only when its signed source or distribution content matches', function (string $installation, bool $loads): void {
    $name = 'capell-app/free-fixture';
    $path = $this->packageClassRoot . '/vendor/' . $name;
    $provider = 'CapellLicenceProvider' . bin2hex(random_bytes(8));
    $manifest = capellManifestV3Array(name: $name, providers: ['metadata' => [$provider]]);
    $manifest['installPath'] = $path;
    writePackageClassFixture($this->packageClassRoot, $name, HashServiceProvider::class, $manifest, 'absent');
    $files = new Filesystem;
    $files->put($path . '/Provider.php', '<?php class ' . $provider . ' extends \\Illuminate\\Support\\ServiceProvider {}');
    $files->put($path . '/runtime.php', '<?php return true;');

    $distribution = [
        'Provider.php' => $files->get($path . '/Provider.php'),
        'capell.json' => $files->get($path . '/capell.json'),
        'composer.json' => $files->get($path . '/composer.json'),
        'runtime.php' => $files->get($path . '/runtime.php'),
    ];
    $source = [...$distribution, 'test.php' => '<?php return true;'];
    $treeDigest = static function (array $contents): string {
        $input = "capell-release-content-v2\0";
        foreach ($contents as $filename => $bytes) {
            $input .= pack('N', strlen($filename)) . $filename . hash('sha256', $bytes, true);
        }

        return hash('sha256', $input);
    };
    $proof = signPackageClassPayload([
        'manifest_version' => 2,
        'purpose' => 'capell-extension-licence-manifest',
        'composer_name' => $name,
        'is_paid' => false,
        'version' => $installation === 'wrong version' ? '1.1.0.0' : '1.0.0.0',
        'source_tree_sha256' => $treeDigest($source),
        'dist_tree_sha256' => $treeDigest($distribution),
        'issued_at' => now()->subDay()->toIso8601String(),
    ]);
    file_put_contents($path . '/capell-licence.json', json_encode($proof, JSON_THROW_ON_ERROR));

    match ($installation) {
        'source' => file_put_contents($path . '/test.php', $source['test.php']),
        'changed' => file_put_contents($path . '/runtime.php', '<?php return false;'),
        'metadata' => file_put_contents($path . '/composer.json', $distribution['composer.json'] . "\n"),
        'added' => file_put_contents($path . '/extra.php', '<?php return true;'),
        'removed' => unlink($path . '/runtime.php'),
        'symlink' => symlink($path . '/composer.json', $path . '/another.json'),
        default => null,
    };

    $providerFile = $path . '/Provider.php';
    if ($installation === 'redirected provider') {
        $providerFile = $this->packageClassRoot . '/unsigned-provider.php';
        $files->put($providerFile, $distribution['Provider.php']);
    }

    $loader = new ClassLoader($installation === 'vendorless provider' ? null : $this->packageClassRoot . '/vendor');
    $loader->addClassMap([$provider => $providerFile]);
    $loader->register(prepend: true);

    $additionalLoader = new ClassLoader;
    if ($installation === 'prepended provider' || $installation === 'git metadata provider') {
        $unsignedProvider = $installation === 'prepended provider'
            ? $this->packageClassRoot . '/unsigned-provider.php'
            : $path . '/.git/Provider.php';
        $files->ensureDirectoryExists(dirname($unsignedProvider));
        $files->put($unsignedProvider, $distribution['Provider.php']);
        $additionalLoader->addClassMap([$provider => $unsignedProvider]);
        $additionalLoader->register(prepend: true);
    }

    try {
        withFreshPackageClassApplication($this->packageClassRoot, true, function (Application $fresh) use ($provider, $loads): void {
            loadPackageClassProviders($fresh);
            expect($fresh->providerIsLoaded($provider))->toBe($loads);
        });
    } finally {
        $additionalLoader->unregister();
        $loader->unregister();
    }

    Http::assertNothingSent();
})->with([
    'exported distribution' => ['dist', true],
    'source checkout' => ['source', true],
    'provider resolved by a vendor-less Composer loader' => ['vendorless provider', true],
    'modified member' => ['changed', false],
    'modified Composer metadata' => ['metadata', false],
    'added member' => ['added', false],
    'removed member' => ['removed', false],
    'symlink member' => ['symlink', false],
    'provider redirected outside the signed tree' => ['redirected provider', false],
    'provider redirected by a prepended vendor-less loader' => ['prepended provider', false],
    'provider redirected to excluded Git metadata' => ['git metadata provider', false],
    'another release' => ['wrong version', false],
]);

it('registers a protected release only through the gated manifest after pinning the issuer', function (
    string $state,
    bool $pinned,
): void {
    $renamed = str_starts_with($state, 'renamed');
    $name = $renamed ? 'vendor/renamed-paid' : 'capell-app/paid-fixture';
    $manifest = capellManifestV3Array(name: $name, surfaces: ['frontend'], providers: ['frontend' => [ManifestBootstrapFrontendProvider::class]]);
    writePackageClassFixture($this->packageClassRoot, $name, ProtectedReleaseBootstrapFixtureProvider::class, $manifest, $renamed ? 'renamed' : 'paid');

    if ($state === 'unregistered') {
        new Filesystem()->put($this->packageClassRoot . '/bootstrap/cache/capell-package-manifests.php', '<?php return [];');
    }

    if (in_array($state, ['active without receipt', 'active with receipt', 'renamed with receipt'], true)) {
        CapellExtension::query()->create([
            'composer_name' => $name, 'status' => ExtensionStatusEnum::Enabled,
            'marketplace_runtime_status' => 'active', 'marketplace_runtime_allowed' => true,
            'marketplace_signed_activation' => $state !== 'active without receipt' ? ['installed_receipt_v2' => packageClassReceipt($name)] : null,
        ]);
    }

    withFreshPackageClassApplication($this->packageClassRoot, $pinned, function (Application $fresh, Application $original) use ($name, $state, $pinned): void {
        $providers = packageClassDiscovery($fresh)->providers();
        expect($fresh->bound('db'))->toBeFalse()
            ->and($fresh->bound(CapellCoreManager::class))->toBeFalse()
            ->and($providers)->toBe([ProtectedReleaseBootstrapFixtureProvider::class]);

        new ProviderRepository($fresh, new Filesystem, $fresh->bootstrapPath('cache/discovery-services.php'))->load($providers);
        expect($fresh->providerIsLoaded(ProtectedReleaseBootstrapFixtureProvider::class))->toBeTrue()
            ->and($fresh->bound('manifest-frontend-registered'))->toBe(! $pinned)
            ->and($fresh->bound('runtime-role.fixture.authoring'))->toBe(! $pinned)
            ->and($fresh->make(ExtensionCommercialPolicy::class)->requiresAuthorisation($name))->toBe($pinned);

        foreach (['db', 'db.schema'] as $binding) {
            $fresh->instance($binding, $original->make($binding));
        }

        loadPackageClassProviders($fresh);
        expect($fresh->bound('manifest-frontend-registered'))->toBe(! $pinned || $state === 'active with receipt')
            ->and(CapellCore::hasPackage($name))->toBe($state !== 'unregistered');
    });
    Http::assertNothingSent();
})->with(['without lifecycle row', 'active without receipt', 'active with receipt', 'unregistered', 'renamed', 'renamed with receipt'])
    ->with(['pinned issuer' => true, 'empty key set' => false]);

it('admits a receipted paid provider on the first registration using the Marketplace instance configuration', function (
    ?array $appConfig,
    bool $expectedProvider,
    bool $tamperReceipt = false,
): void {
    $name = 'capell-app/paid-fixture';
    $manifest = capellManifestV3Array(name: $name, surfaces: ['frontend'], providers: ['frontend' => [ManifestBootstrapFrontendProvider::class]]);
    writePackageClassFixture($this->packageClassRoot, $name, ProtectedReleaseBootstrapFixtureProvider::class, $manifest, 'paid');

    CapellExtension::query()->create([
        'composer_name' => $name, 'status' => ExtensionStatusEnum::Enabled,
        'marketplace_runtime_status' => 'active', 'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt_v2' => $tamperReceipt
            ? [...packageClassReceipt($name), 'package_version' => '9.9.9']
            : packageClassReceipt($name)],
    ]);

    $originalInstance = getenv('CAPELL_MARKETPLACE_INSTANCE_ID');
    putenv('CAPELL_MARKETPLACE_INSTANCE_ID=' . PACKAGE_CLASS_INSTANCE);

    try {
        withFreshPackageClassApplication($this->packageClassRoot, true, function (Application $fresh, Application $original) use ($appConfig, $expectedProvider): void {
            $config = new Repository(Arr::except($original->make(Illuminate\Contracts\Config\Repository::class)->all(), ['capell']));
            if ($appConfig !== null) {
                $config->set('capell', $appConfig);
            }

            $fresh->instance('config', $config);
            $fresh->instance('events', new Dispatcher($fresh));

            $database = $original->make(DatabaseManager::class);
            $fresh->instance('db', $database);
            $fresh->instance('db.schema', $database->connection()->getSchemaBuilder());
            $fresh->singleton(HttpKernelContract::class, HttpKernel::class);
            $fresh->register(FilesystemServiceProvider::class);
            $fresh->register(ViewServiceProvider::class);

            $fresh->register(CapellServiceProvider::class);

            expect($fresh->bound('manifest-frontend-registered'))->toBe($expectedProvider);
        });
    } finally {
        putenv($originalInstance === false ? 'CAPELL_MARKETPLACE_INSTANCE_ID' : 'CAPELL_MARKETPLACE_INSTANCE_ID=' . $originalInstance);
    }

    Http::assertNothingSent();
})->with([
    'unpublished Capell config' => [null, true],
    'Capell config with an unrelated override' => [['schedule' => ['run_frequent_commands_in_process' => true]], true],
    // A genuine receipt held by another instance is an unactivated site: it keeps running with the admin licence
    // notice. An altered receipt is never honoured, whatever the instance.
    'a genuine receipt for a different instance' => [['extensions' => ['marketplace_instance_id' => 'wrong-instance']], true],
    'an altered receipt for a different instance' => [['extensions' => ['marketplace_instance_id' => 'wrong-instance']], false, true],
]);

it('keeps authoring providers out of empty-key public discovery through a protected bootstrap', function (): void {
    $root = $this->packageClassRoot;
    withFreshPackageClassApplication($root, false, function (Application $fresh): void {
        $fresh->instance(RuntimeRoleSelectionData::class, RuntimeRoleSelectionData::fromConfiguredValue(RuntimeRole::Public->value));

        $fresh->register(ProtectedReleaseBootstrapFixtureProvider::class);
        expect($fresh->bound('manifest-frontend-registered'))->toBeTrue()
            ->and($fresh->bound('runtime-role.fixture.authoring'))->toBeFalse();
    });
});

it('installs a signed free Capell package offline using its Composer-only provider command', function (): void {
    $name = 'capell-app/free-install-fixture';
    $manifest = capellManifestV3Array(name: $name);
    $manifest['commands']['install'] = 'test:discovered-free-install';
    $root = $this->packageClassRoot;
    writePackageClassFixture($root, $name, DiscoveredFreeInstallFixtureProvider::class, $manifest, 'free');

    $app = app();
    $base = $app->basePath();
    $app->setBasePath($root);
    try {
        $app->instance(TrustedMarketplaceIssuerKeys::class, packageClassKeys(true));
        CapellCore::registerManifestPackage(resolve(ManifestLoader::class)->load($root . '/vendor/' . $name . '/capell.json'));
        $discovery = packageClassDiscovery($app);
        $app->instance(PackageManifest::class, $discovery);
        $providers = $app->make(PackageManifest::class)->providers();
        expect($providers)->toBe([DiscoveredFreeInstallFixtureProvider::class]);
        new ProviderRepository($app, new Filesystem, $app->bootstrapPath('cache/discovery-services.php'))->load($providers);

        expect(Artisan::call('capell:extension-install', ['extension' => $name, '--no-interaction' => true]))->toBe(0)
            ->and($app->bound('discovered-free-install-ran'))->toBeTrue()
            ->and(CapellExtension::query()->where('composer_name', $name)->sole()->status)->toBe(ExtensionStatusEnum::Enabled);
        Http::assertNothingSent();
    } finally {
        $app->setBasePath($base);
    }
});

it('preserves empty-key Laravel provider and alias discovery after runtime role filtering', function (): void {
    $root = $this->packageClassRoot;
    $fixtures = [
        ['name' => 'capell-app/admin', 'extra' => ['laravel' => [
            'providers' => ['Capell\\Admin\\AdminServiceProvider'],
            'aliases' => ['Admin' => 'Capell\\Admin\\AdminFacade'],
        ]]],
        ['name' => 'capell-app/free-fixture', 'extra' => ['laravel' => [
            'providers' => [HashServiceProvider::class, 'Filament\\ExampleProvider'],
            'aliases' => ['Free' => 'Vendor\\FreeFacade', 'AdminOnly' => 'Filament\\ExampleFacade'],
        ]]],
        ['name' => 'capell-app/legacy-paid-fixture', 'extra' => ['laravel' => [
            'providers' => [ProtectedReleaseBootstrapFixtureProvider::class],
        ]]],
    ];
    $files = new Filesystem;
    $files->put($root . '/vendor/composer/installed.json', json_encode(['packages' => $fixtures], JSON_THROW_ON_ERROR));

    withFreshPackageClassApplication($root, false, function (Application $fresh) use ($files, $fixtures): void {
        // The legacy policy considers a registered paid flag even before a key is pinned.
        $fresh->singleton(CapellCoreManager::class);
        $fresh->singleton(CapellPackageRegistry::class);
        CapellCore::registerPackage('capell-app/legacy-paid-fixture');
        CapellCore::getPackage('capell-app/legacy-paid-fixture')->isPaid = true;
        $plain = new PackageManifest($files, $fresh->basePath(), $fresh->bootstrapPath('cache/plain.php'));
        $policy = new RuntimeRoleProviderPolicy;
        $role = RuntimeRole::Public;
        $filtered = [];
        foreach ($fixtures as $fixture) {
            $entry = $policy->filterLaravelPackage($fixture['name'], $fixture['extra']['laravel'], $role);
            if ($entry !== null) {
                $filtered[$fixture['name']] = $entry;
            }
        }

        $expectedPath = $fresh->bootstrapPath('cache/role-filtered.php');
        $files->put($expectedPath, '<?php return ' . var_export($filtered, true) . ';');
        $expected = new PackageManifest($files, $fresh->basePath(), $expectedPath);

        expect(packageClassDiscovery($fresh)->providers())->toBe($plain->providers())
            ->and(packageClassDiscovery($fresh)->aliases())->toBe($plain->aliases())
            ->and(packageClassDiscovery($fresh, $role)->providers())->toBe($expected->providers())
            ->and(packageClassDiscovery($fresh, $role)->aliases())->toBe($expected->aliases());
    });
});

it('preserves the empty-key runtime gate for free and paid lifecycle rows', function (bool $paid, ?string $status, bool $allowed): void {
    app()->instance(TrustedMarketplaceIssuerKeys::class, packageClassKeys(false));
    $row = new CapellExtension([
        'composer_name' => 'capell-app/legacy-fixture', 'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => $paid, 'marketplace_runtime_status' => $status,
        'marketplace_runtime_allowed' => true,
    ]);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation($row->composer_name, $row))->toBe($paid)
        ->and(ResolveExtensionRuntimeGateAction::run($row)->allowed)->toBe($allowed);
})->with([
    'free without activation' => [false, null, true],
    'paid without activation' => [true, null, false],
    'paid with legacy active status' => [true, 'active', true],
    'revoked regardless of paid flag' => [false, 'revoked', false],
]);
