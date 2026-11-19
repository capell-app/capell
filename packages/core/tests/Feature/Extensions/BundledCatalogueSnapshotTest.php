<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Carbon\CarbonImmutable;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->catalogueRoot = sys_get_temp_dir() . '/capell-bundled-catalogue-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->catalogueRoot);
    $this->snapshotPath = $this->catalogueRoot . '/catalogue-snapshot.json';
    app()->useStoragePath($this->catalogueRoot . '/storage');
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(keys: [
        'test-issuer' => base64_encode(sodium_crypto_sign_publickey($keys)),
    ]));
    app()->instance(CommercialCatalogueStore::class, new CommercialCatalogueStore(snapshotPath: $this->snapshotPath));
    app()->forgetInstance(ExtensionCommercialPolicy::class);

    $this->catalogueLoader = useBundledCatalogueCoreVersion($this->catalogueRoot, 'v1.2.0', 'capell-app/capell');

    resolve(ExtensionCommercialPolicy::class)->clear();
    $this->freezeTime();
});

afterEach(function (): void {
    $this->catalogueLoader->unregister();
    resolve(ExtensionCommercialPolicy::class)->clear();
    File::deleteDirectory($this->catalogueRoot);
});

function useBundledCatalogueCoreVersion(string $root, string $version, string $package): ClassLoader
{
    $vendor = $root . '/releases/' . $version . '/vendor';
    File::ensureDirectoryExists($vendor . '/composer');
    $versions = ['capell-app/core' => ['replaced' => [$version]]];
    $versions[$package] = ['pretty_version' => $version];
    File::put($vendor . '/composer/installed.php', '<?php return ' . var_export(['versions' => $versions], true) . ';');
    $loader = new ClassLoader($vendor);
    $loader->register(prepend: true);

    return $loader;
}

/**
 * @param  array<string, bool>  $packages
 * @return array<string, mixed>
 */
function signedBundledCatalogue(
    array $packages,
    int $sequence = 10,
    int $validDays = 30,
    int $issuedDaysAgo = 1,
    string $seedByte = "\x01",
    ?string $coreVersion = 'v1.2.0',
): array {
    $issued = now()->subDays($issuedDaysAgo);
    $catalogue = [
        'catalogue_version' => 1,
        'purpose' => 'capell-extension-commercial-classification',
        'sequence' => $sequence,
        'issued_at' => $issued->toIso8601String(),
        'valid_until' => $issued->copy()->addDays($validDays)->toIso8601String(),
        'packages' => [],
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
    ];
    if ($coreVersion !== null) {
        $catalogue['core_version'] = $coreVersion;
    }

    foreach ($packages as $name => $paid) {
        $catalogue['packages'][] = ['composer_name' => $name, 'is_paid' => $paid];
    }

    $keys = sodium_crypto_sign_seed_keypair(str_repeat($seedByte, 32));
    $catalogue['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
        CanonicalizeProjectBuildManifestSigningInputAction::run($catalogue),
        sodium_crypto_sign_secretkey($keys),
    ));

    return $catalogue;
}

/** @param array<string, mixed> $catalogue */
function writeBundledCatalogue(string $path, array $catalogue): void
{
    File::put($path, json_encode($catalogue, JSON_THROW_ON_ERROR));
}

it('honours bundled free listings from both vendors without a stored catalogue', function (): void {
    $catalogue = signedBundledCatalogue(['capell-app/x' => false, 'vendor/free' => false]);
    writeBundledCatalogue($this->snapshotPath, $catalogue);
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);

    expect($policy->requiresAuthorisation('capell-app/x'))->toBeFalse()
        ->and($policy->requiresAuthorisation('vendor/free'))->toBeFalse()
        ->and(File::exists($store->path()))->toBeFalse();
});

it('requires authorisation for packages omitted from the bundled snapshot', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/listed-free' => false]));
    $policy = resolve(ExtensionCommercialPolicy::class);

    expect($policy->requiresAuthorisation('vendor/listed-free'))->toBeFalse()
        ->and($policy->requiresAuthorisation('vendor/unlisted'))->toBeTrue()
        ->and($policy->requiresAuthorisation('capell-app/unlisted-new'))->toBeTrue()
        ->and($policy->isRecognised('vendor/unlisted'))->toBeFalse();
});

it('requires authorisation for a recognised bundled paid listing', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/paid' => true]));
    $policy = resolve(ExtensionCommercialPolicy::class);

    expect($policy->isRecognised('vendor/paid'))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/paid'))->toBeTrue();
});

it('fails closed when a bundled free listing is replaced by an invalid snapshot', function (string $defect): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    $catalogue = match ($defect) {
        'tampered' => signedBundledCatalogue(['vendor/free' => true]),
        'wrong key' => signedBundledCatalogue(['vendor/free' => false], seedByte: "\x02"),
        'expired' => signedBundledCatalogue(['vendor/free' => false], issuedDaysAgo: 31),
        default => signedBundledCatalogue(['vendor/free' => false], validDays: 31),
    };
    $catalogue['packages'][0]['is_paid'] = false;
    writeBundledCatalogue($this->snapshotPath, $catalogue);
    $policy->clear();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
})->with(['tampered', 'wrong key', 'expired', 'overlong']);

it('stops honouring the bundled listing when its signed validity ends', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    $this->travel(29)->days();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('uses the stored live catalogue even when the bundle has an equal or higher sequence', function (int $liveSequence): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();
    expect($store->accept(signedBundledCatalogue(['vendor/free' => true], sequence: $liveSequence, coreVersion: null)))->toBeTrue();

    $policy->clear();
    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue()
        ->and($store->current()['sequence'] ?? null)->toBe($liveSequence);
})->with(['higher bundle' => 9, 'equal bundle' => 10, 'lower bundle' => 11]);

it('stops using the bundle when another store writes the first live catalogue', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    $live = signedBundledCatalogue(['vendor/free' => true], sequence: 9, coreVersion: null);
    expect(new CommercialCatalogueStore()->accept($live))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('fails closed after a live catalogue expires even while the bundled snapshot is valid', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($store->accept(signedBundledCatalogue(['vendor/free' => true], sequence: 11, validDays: 2, coreVersion: null)))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/free'))->toBeTrue();

    $this->travel(2)->days();
    expect($store->current())->toBeNull();

    $policy->clear();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue()
        ->and($store->current())->toBeNull()
        ->and($store->accept(signedBundledCatalogue(['vendor/free' => false], sequence: 10, coreVersion: null)))->toBeFalse();
});

it('fails closed when the live sequence floor survives an unreadable catalogue payload', function (bool $hasCatalogue): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false], sequence: 12));
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);
    $live = signedBundledCatalogue(['vendor/free' => true], sequence: 11, coreVersion: null);
    expect($store->accept($live))->toBeTrue();

    $live['packages'][0]['is_paid'] = false;
    $stored = ['highest_sequence' => 11];
    if ($hasCatalogue) {
        $stored['catalogue'] = $live;
    }

    writeBundledCatalogue($store->path(), $stored);
    $policy->clear();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue()
        ->and($store->accept(signedBundledCatalogue(['vendor/free' => false], sequence: 10, coreVersion: null)))->toBeFalse();
})->with(['invalid signature' => true, 'missing catalogue' => false]);

it('rejects a still-valid signed free listing from an older Core release', function (string $package): void {
    $this->catalogueLoader->unregister();
    $this->catalogueLoader = useBundledCatalogueCoreVersion($this->catalogueRoot, 'v1.3.0', $package);
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false], coreVersion: 'v1.3.0'));
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false], coreVersion: 'v1.2.0'));
    $policy->clear();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
})->with(['capell-app/core', 'capell-app/capell']);

it('rejects a signed bundled listing that has no release binding', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false], coreVersion: null));

    expect(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('keeps the snapshot release restriction when a bundle is submitted as a live delivery', function (): void {
    $store = resolve(CommercialCatalogueStore::class);
    $catalogue = signedBundledCatalogue(['vendor/free' => false]);

    expect($store->accept($catalogue))->toBeFalse()
        ->and(File::exists($store->path()))->toBeFalse()
        ->and(resolve(ExtensionCommercialPolicy::class)->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('requires authorisation for an omission when the snapshot expires during the policy decision', function (): void {
    $catalogue = signedBundledCatalogue(['vendor/listed-free' => false]);
    writeBundledCatalogue($this->snapshotPath, $catalogue);
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/listed-free'))->toBeFalse();

    $expiry = CarbonImmutable::parse($catalogue['valid_until']);
    $reads = 0;
    CarbonImmutable::withTestNow(
        static function () use ($expiry, &$reads): CarbonImmutable {
            return ++$reads < 3 ? $expiry->subSecond() : $expiry;
        },
        static function () use ($policy): void {
            expect($policy->requiresAuthorisation('vendor/unlisted'))->toBeTrue();
        },
    );
});

it('fails closed when the bundled file is missing or cannot be decoded', function (): void {
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();

    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $policy->clear();
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    File::put($this->snapshotPath, 'garbage');
    $policy->clear();
    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('ships a verified in-date snapshot whenever Core pins an issuer key', function (): void {
    $keys = new TrustedMarketplaceIssuerKeys;
    $document = json_decode(File::get(TrustedMarketplaceIssuerKeys::defaultPath()), true, flags: JSON_THROW_ON_ERROR);
    expect($document['keys'] ?? null)->toBeArray();
    if ($document['keys'] === []) {
        expect($keys->all() === [])->toBeTrue();

        return;
    }

    expect($keys->all())->not->toBeEmpty();
    $this->catalogueLoader->unregister();
    app()->instance(TrustedMarketplaceIssuerKeys::class, $keys);
    $store = new CommercialCatalogueStore;
    expect(File::isFile(CommercialCatalogueStore::defaultSnapshotPath()))->toBeTrue()
        ->and($store->current())->not->toBeNull();
});
