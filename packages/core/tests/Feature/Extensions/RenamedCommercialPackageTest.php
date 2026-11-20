<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Testing\Http\FakeHostAddressResolver;
use Capell\Core\Tests\Support\ManifestBootstrapFrontendProvider;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;

/*
 * Threat: a site owner edits only Composer and Capell metadata (installed.json, the package
 * composer.json, capell.json), never PHP. Renaming a paid package to an unlisted vendor must not
 * make its code free at any gate, while a genuinely third-party package stays free.
 */

const RENAMED_PAID_PROVIDER = 'Vendor\\Commercial\\CommercialServiceProvider';

beforeEach(function (): void {
    FakeHostAddressResolver::bind();
    $this->renamedBase = app()->basePath();
    $this->renamedRoot = sys_get_temp_dir() . '/capell-renamed-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->renamedRoot . '/vendor/composer');
    File::ensureDirectoryExists($this->renamedRoot . '/bootstrap/cache');
    app()->setBasePath($this->renamedRoot);
    config()->set('app.url', 'https://renamed.test');
    config()->set('capell.extensions.verification.strict', true);

    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x02", 32));
    $this->renamedKeys = $keys;
    // A committed issuer key switches enforcement on.
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(keys: ['renamed-issuer' => base64_encode(sodium_crypto_sign_publickey($keys))]));
    File::delete(resolve(CommercialCatalogueStore::class)->path());
    resolve(ExtensionCommercialPolicy::class)->clear();
    CapellCore::clearPackages();
    $this->renamedCalls = 0;
    $test = $this;
    Artisan::command('test:renamed-install', function () use ($test): int {
        $test->renamedCalls++;

        return 0;
    });
});

afterEach(function (): void {
    File::delete(resolve(CommercialCatalogueStore::class)->path());
    resolve(ExtensionCommercialPolicy::class)->clear();
    app()->setBasePath($this->renamedBase);
    CapellCore::clearPackages();
    File::deleteDirectory($this->renamedRoot);
});

/** Signs a catalogue listing the original paid package, optionally with its namespaces. */
/** @param list<string> $namespaces */
function acceptRenamedCatalogue(object $test, bool $withNamespaces, array $namespaces = ['Vendor\\Commercial\\']): void
{
    $entry = ['composer_name' => 'vendor/commercial', 'is_paid' => true];
    if ($withNamespaces) {
        $entry['namespaces'] = $namespaces;
    }

    $catalogue = [
        'catalogue_version' => 1, 'purpose' => 'capell-extension-commercial-classification',
        'sequence' => 1_800_000_000, 'issued_at' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addDays(29)->toIso8601String(),
        'packages' => [$entry, ['composer_name' => 'vendor/listed-free', 'is_paid' => false]],
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'renamed-issuer', 'value' => ''],
    ];
    $catalogue['signature']['value'] = base64_encode(sodium_crypto_sign_detached(CanonicalizeProjectBuildManifestSigningInputAction::run($catalogue), sodium_crypto_sign_secretkey($test->renamedKeys)));
    expect(resolve(CommercialCatalogueStore::class)->accept($catalogue))->toBeTrue();
    resolve(ExtensionCommercialPolicy::class)->clear();
}

/**
 * Writes one installed package into installed.json, its composer.json and capell.json, the way
 * the owner would after renaming it.
 *
 * @param  array<string, string>  $psr4
 * @param  list<string>  $laravelProviders
 * @param  array<string, string>  $psr0
 */
function installRenamedFixture(object $test, string $name, array $psr4, array $laravelProviders, string $bucket = 'frontend', array $psr0 = []): void
{
    $root = $test->renamedRoot;
    File::ensureDirectoryExists($root . '/vendor/' . $name . '/src');
    $manifest = capellManifestV3Array(name: $name, surfaces: ['frontend'], providers: ['frontend' => []]);
    $manifest['product']['tier'] = 'free';
    $manifest['version'] = '1.0.0';
    $manifest['commands']['install'] = 'test:renamed-install';
    $composer = [
        'name' => $name, 'version' => '1.0.0', 'license' => ['MIT'], 'type' => 'library',
        'install-path' => '../' . $name, 'autoload' => ['psr-4' => $psr4, ...($psr0 === [] ? [] : ['psr-0' => $psr0])],
        'extra' => ['laravel' => ['providers' => $laravelProviders]],
    ];
    File::put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$composer]], JSON_THROW_ON_ERROR));
    File::put($root . '/vendor/' . $name . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    File::put($root . '/vendor/' . $name . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    CapellCore::clearPackages();
    CapellCore::registerManifestPackage(resolve(ManifestLoader::class)->load($root . '/vendor/' . $name . '/capell.json'));
    // The boot cache carries a Capell provider so the fresh bootstrap has one to gate.
    $manifest['providers'] = [$bucket => [ManifestBootstrapFrontendProvider::class]];
    File::put($root . '/bootstrap/cache/capell-package-manifests.php', '<?php return ' . var_export([$name => $manifest], true) . ';');
    resolve(ExtensionCommercialPolicy::class)->clear();
}

/** Provider loading: whether a fresh application bootstrap registers the package's Capell provider. */
function renamedProviderLoaded(object $test, string $name): bool
{
    CapellExtension::query()->create([
        'composer_name' => $name, 'status' => ExtensionStatusEnum::Enabled,
        'marketplace_runtime_status' => null, 'marketplace_runtime_allowed' => false,
    ]);
    $original = app();
    $fresh = new Application($test->renamedRoot);
    try {
        foreach (['config', 'db', 'db.schema', 'events', 'cache', 'log', 'translator', 'encrypter', TrustedMarketplaceIssuerKeys::class] as $binding) {
            $fresh->instance($binding, $original->make($binding));
        }

        $fresh->instance('env', 'testing');
        $fresh->singleton(CapellCoreManager::class);
        $fresh->singleton(CapellPackageRegistry::class);
        $fresh->singleton(ExtensionLifecycleRepository::class);
        $fresh->bind(ExtensionEntitlements::class, NullExtensionEntitlements::class);
        Container::setInstance($fresh);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($fresh);
        $fresh->make(PackageRegistryBootstrapper::class)->bootstrap();

        return $fresh->providerIsLoaded(ManifestBootstrapFrontendProvider::class);
    } finally {
        Container::setInstance($original);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($original);
    }
}

it('keeps a paid package paid at install, enable and manifest provider loading after a rename', function (string $name, array $psr4, bool $trustedName = false): void {
    acceptRenamedCatalogue($this, withNamespaces: true);
    installRenamedFixture($this, $name, $psr4, [RENAMED_PAID_PROVIDER]);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation($name))->toBeTrue();
    if ($trustedName) {
        // The CLI never runs a lifecycle for a Core name, and Core names always read as enabled;
        // what matters is that the renamed code is gated everywhere it could still run.
        expect(Artisan::call('capell:extension-install', ['extension' => $name, '--no-interaction' => true]))->not->toBe(0);
    } else {
        expect(fn () => Artisan::call('capell:extension-install', ['extension' => $name, '--no-interaction' => true]))->toThrow(RuntimeException::class, 'authorisation');
    }

    expect($this->renamedCalls)->toBe(0);
    expect(fn () => EnablePackageAction::run(CapellCore::getPackage($name)))->toThrow(RuntimeException::class, 'authorisation');
    expect(CapellCore::isPackageEnabled($name))->toBe($trustedName)
        ->and(renamedProviderLoaded($this, $name))->toBeFalse();
})->with([
    'unlisted vendor, paid namespace kept' => ['acme/renamed', ['Vendor\\Commercial\\' => 'src/']],
    'unlisted vendor, namespace renamed, provider class still paid' => ['acme/renamed', ['Acme\\Renamed\\' => 'src/']],
    'broader namespace covering the paid one' => ['acme/renamed', ['Vendor\\' => 'src/']],
    'renamed to a listed free package' => ['vendor/listed-free', ['Vendor\\Commercial\\' => 'src/']],
    'renamed to a trusted Core name' => ['capell-app/installer', ['Vendor\\Commercial\\' => 'src/'], true],
]);

it('keeps a genuinely third-party package free at every gate', function (): void {
    acceptRenamedCatalogue($this, withNamespaces: true);
    installRenamedFixture($this, 'acme/thirdparty', ['Acme\\Thirdparty\\' => 'src/'], ['Acme\\Thirdparty\\ThirdpartyServiceProvider']);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('acme/thirdparty'))->toBeFalse();
    expect(Artisan::call('capell:extension-install', ['extension' => 'acme/thirdparty', '--no-interaction' => true]))->toBe(0)
        ->and($this->renamedCalls)->toBe(1)
        ->and(CapellCore::isPackageEnabled('acme/thirdparty'))->toBeTrue();
    CapellExtension::query()->where('composer_name', 'acme/thirdparty')->delete();
    expect(renamedProviderLoaded($this, 'acme/thirdparty'))->toBeTrue();
});

it('falls back to name classification for a catalogue issued before namespaces were published', function (): void {
    acceptRenamedCatalogue($this, withNamespaces: false);
    installRenamedFixture($this, 'acme/renamed', ['Vendor\\Commercial\\' => 'src/'], [RENAMED_PAID_PROVIDER]);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('acme/renamed'))->toBeFalse()
        ->and(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/commercial'))->toBeTrue();
});

it('ignores single-segment catalogue namespaces that unrelated vendors share', function (): void {
    acceptRenamedCatalogue($this, withNamespaces: true, namespaces: ['Acme\\', 'Vendor\\Commercial\\']);
    installRenamedFixture($this, 'acme/thirdparty', ['Acme\\Thirdparty\\' => 'src/'], ['Acme\\Thirdparty\\ThirdpartyServiceProvider']);

    expect(resolve(CommercialCatalogueStore::class)->paidNamespaces())->toBe(['vendor\\commercial\\'])
        ->and(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('acme/thirdparty'))->toBeFalse()
        ->and(renamedProviderLoaded($this, 'acme/thirdparty'))->toBeTrue();
});

it('keeps a third-party package with a root PSR-0 mapping free', function (): void {
    acceptRenamedCatalogue($this, withNamespaces: true);
    installRenamedFixture($this, 'acme/thirdparty', ['Acme\\Thirdparty\\' => 'src/'], ['Acme\\Thirdparty\\ThirdpartyServiceProvider'], psr0: ['' => 'src/']);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('acme/thirdparty'))->toBeFalse()
        ->and(renamedProviderLoaded($this, 'acme/thirdparty'))->toBeTrue();
});

it('treats a PSR-0 prefix as a raw string prefix that can load the paid namespace', function (): void {
    acceptRenamedCatalogue($this, withNamespaces: true);
    installRenamedFixture($this, 'acme/renamed', ['Acme\\Renamed\\' => 'src/'], ['Acme\\Renamed\\RenamedServiceProvider'], psr0: ['Vendor\\Comm' => 'src/']);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('acme/renamed'))->toBeTrue()
        ->and(renamedProviderLoaded($this, 'acme/renamed'))->toBeFalse();
});

it('gates a paid provider named only in the boot cache manifest', function (): void {
    acceptRenamedCatalogue($this, withNamespaces: true, namespaces: ['Vendor\\Commercial\\', 'Capell\\Core\\Tests\\Support\\']);
    // Vendor metadata claims nothing paid; only the cached manifest names the paid provider.
    installRenamedFixture($this, 'acme/thirdparty', ['Acme\\Thirdparty\\' => 'src/'], ['Acme\\Thirdparty\\ThirdpartyServiceProvider']);

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('acme/thirdparty'))->toBeFalse()
        ->and(renamedProviderLoaded($this, 'acme/thirdparty'))->toBeFalse();
});
