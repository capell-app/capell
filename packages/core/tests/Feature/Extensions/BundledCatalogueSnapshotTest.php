<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
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

    resolve(ExtensionCommercialPolicy::class)->clear();
    $this->freezeTime();
});

afterEach(function (): void {
    resolve(ExtensionCommercialPolicy::class)->clear();
    File::deleteDirectory($this->catalogueRoot);
});

/**
 * @param  array<string, bool>  $packages
 * @return array<string, mixed>
 */
function signedBundledCatalogue(array $packages, int $sequence = 10, int $validDays = 120, int $issuedDaysAgo = 1, string $seedByte = "\x01"): array
{
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
        ->and(File::exists($store->path()))->toBeFalse()
        ->and($store->accept($catalogue))->toBeFalse();
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
        'expired' => signedBundledCatalogue(['vendor/free' => false], issuedDaysAgo: 121),
        default => signedBundledCatalogue(['vendor/free' => false], validDays: 121),
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

    $this->travel(119)->days();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('accepts live deliveries and only displaces the snapshot with a higher sequence', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);
    $policy->requiresAuthorisation('vendor/free');

    expect($store->accept(signedBundledCatalogue(['vendor/free' => true], sequence: 9, validDays: 30)))->toBeTrue();
    $policy->clear();
    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    expect($store->accept(signedBundledCatalogue(['vendor/free' => true], sequence: 10, validDays: 30)))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/free'))->toBeFalse();

    expect($store->accept(signedBundledCatalogue(['vendor/free' => true], sequence: 11, validDays: 30)))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
    $policy->clear();
    expect($policy->requiresAuthorisation('vendor/free'))->toBeTrue();
});

it('falls back to the snapshot when the higher stored sequence expires', function (): void {
    writeBundledCatalogue($this->snapshotPath, signedBundledCatalogue(['vendor/free' => false]));
    $store = resolve(CommercialCatalogueStore::class);
    $policy = resolve(ExtensionCommercialPolicy::class);
    expect($store->accept(signedBundledCatalogue(['vendor/free' => true], sequence: 11, validDays: 30)))->toBeTrue()
        ->and($policy->requiresAuthorisation('vendor/free'))->toBeTrue();

    $this->travel(30)->days();
    $policy->clear();

    expect($policy->requiresAuthorisation('vendor/free'))->toBeFalse();
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
    app()->instance(TrustedMarketplaceIssuerKeys::class, $keys);
    $store = new CommercialCatalogueStore;
    expect(File::isFile(CommercialCatalogueStore::defaultSnapshotPath()))->toBeTrue()
        ->and($store->current())->not->toBeNull();
});
