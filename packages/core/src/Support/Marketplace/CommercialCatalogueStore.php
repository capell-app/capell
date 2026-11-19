<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Actions\Marketplace\VerifyMarketplaceIssuerSignatureAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Holds issuer-signed paid/free classifications from live deliveries and the bundled snapshot.
 * The highest accepted live sequence is kept so a replayed older delivery is refused.
 */
final class CommercialCatalogueStore
{
    public const int CATALOGUE_VERSION = 1;

    public const string PURPOSE = 'capell-extension-commercial-classification';

    /**
     * The stored sequence floor lives in storage the site owner controls, so it only stops
     * replayed deliveries. The signed validity window is what bounds an owner restoring an
     * older catalogue: once it ends the catalogue stops counting and Core fails closed.
     */
    public const int MAX_VALIDITY_DAYS = 30;

    public const int SNAPSHOT_MAX_VALIDITY_DAYS = 30;

    private const int CLOCK_SKEW_SECONDS = 300;

    private bool $loaded = false;

    private bool $hasLiveState = false;

    private ?CommercialCatalogue $catalogue = null;

    private bool $snapshotLoaded = false;

    private ?CommercialCatalogue $snapshot = null;

    private int $highestSequence = 0;

    private readonly Filesystem $files;

    // Built without the container: Laravel package discovery consults the store before
    // the filesystem binding exists.
    public function __construct(private readonly ?string $snapshotPath = null)
    {
        $this->files = new Filesystem;
    }

    public static function defaultSnapshotPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/marketplace/catalogue-snapshot.json';
    }

    /** @param array<string, mixed> $catalogue */
    public static function isValid(array $catalogue, int $maxValidityDays = self::MAX_VALIDITY_DAYS): bool
    {
        if (($catalogue['catalogue_version'] ?? null) !== self::CATALOGUE_VERSION
            || ($catalogue['purpose'] ?? null) !== self::PURPOSE
            || ! is_int($catalogue['sequence'] ?? null) || $catalogue['sequence'] < 1
            || ! is_array($catalogue['packages'] ?? null)
            || ! is_string($catalogue['issued_at'] ?? null) || ! is_string($catalogue['valid_until'] ?? null)
            || ! self::withinValidityWindow($catalogue['issued_at'], $catalogue['valid_until'], $maxValidityDays)) {
            return false;
        }

        $seen = [];
        foreach ($catalogue['packages'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['composer_name'] ?? null) || $entry['composer_name'] === ''
                || ! is_bool($entry['is_paid'] ?? null)) {
                return false;
            }

            $name = strtolower($entry['composer_name']);
            if (isset($seen[$name])) {
                return false;
            }

            $seen[$name] = true;
        }

        return VerifyMarketplaceIssuerSignatureAction::run($catalogue);
    }

    public function path(): string
    {
        return storage_path('app/capell/marketplace/commercial-catalogue.json');
    }

    public function clear(): void
    {
        $this->loaded = false;
        $this->hasLiveState = false;
        $this->catalogue = null;
        $this->highestSequence = 0;
        $this->snapshotLoaded = false;
        $this->snapshot = null;
    }

    /** @return array<string, mixed>|null a verified catalogue, or null when none is held */
    public function current(): ?array
    {
        return $this->read()?->document;
    }

    public function read(): ?CommercialCatalogue
    {
        $this->load();
        if (! $this->hasLiveState) {
            $this->loadSnapshot();
        }

        $catalogue = $this->hasLiveState ? $this->catalogue : $this->snapshot;

        return $catalogue instanceof CommercialCatalogue && CarbonImmutable::now()->lessThan($catalogue->expiresAt) ? $catalogue : null;
    }

    /** The bundled snapshot requires authorisation for omissions. */
    public function classification(string $composerName): ?bool
    {
        return $this->read()?->classification($composerName);
    }

    /**
     * Namespaces the issuer lists under paid entries, normalised by InstalledPackageCodeIdentity.
     * A catalogue issued before namespaces were published lists none, which keeps the
     * composer-name classification alone. Free entries never contribute.
     *
     * @return list<string>
     */
    public function paidNamespaces(): array
    {
        return $this->read()?->paidNamespaces() ?? [];
    }

    public function isListed(string $composerName): bool
    {
        return $this->read()?->isListed($composerName) ?? false;
    }

    /** @param array<string, mixed> $catalogue */
    public function accept(array $catalogue): bool
    {
        if (! $this->isValidLiveCatalogue($catalogue)) {
            return false;
        }

        $this->load();
        $sequence = $catalogue['sequence'];
        if ($sequence < $this->highestSequence) {
            return false;
        }

        if ($sequence === $this->highestSequence && $this->catalogue instanceof CommercialCatalogue) {
            // The same sequence must be the same signed document, never a second variant.
            return $this->signingInput($catalogue) === $this->signingInput($this->catalogue->document);
        }

        $directory = dirname($this->path());
        $this->files->ensureDirectoryExists($directory);
        $temporary = $directory . '/.commercial-catalogue-' . bin2hex(random_bytes(8)) . '.json';
        $this->files->put($temporary, json_encode(['highest_sequence' => $sequence, 'catalogue' => $catalogue], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->files->move($temporary, $this->path());

        $this->catalogue = new CommercialCatalogue($catalogue);
        $this->highestSequence = $sequence;
        $this->hasLiveState = true;

        return true;
    }

    private static function withinValidityWindow(string $issuedAt, string $validUntil, int $maxValidityDays): bool
    {
        try {
            $issued = CarbonImmutable::parse($issuedAt);
            $until = CarbonImmutable::parse($validUntil);
        } catch (Throwable) {
            return false;
        }

        $now = CarbonImmutable::now();

        return $issued->lessThanOrEqualTo($now->addSeconds(self::CLOCK_SKEW_SECONDS))
            && $until->greaterThan($issued)
            && $until->lessThanOrEqualTo($issued->addDays($maxValidityDays))
            && $now->lessThan($until);
    }

    /** @param array<string, mixed> $catalogue */
    private function isValidLiveCatalogue(array $catalogue): bool
    {
        // A release-bound document must always pass the bundled snapshot checks.
        return ! array_key_exists('core_version', $catalogue) && self::isValid($catalogue);
    }

    private function load(): void
    {
        // A store serving a bundle must notice the first live delivery from another worker.
        if ($this->loaded && ($this->hasLiveState || ! $this->snapshot instanceof CommercialCatalogue)) {
            return;
        }

        $this->loaded = true;
        try {
            $path = $this->path();
            $this->hasLiveState = $this->files->exists($path);
            if (! $this->hasLiveState) {
                return;
            }

            $stored = json_decode($this->files->get($path), true);
        } catch (Throwable) {
            $this->hasLiveState = true;

            return;
        }

        if (! is_array($stored) || ! is_int($stored['highest_sequence'] ?? null) || $stored['highest_sequence'] < 1) {
            return;
        }

        $this->highestSequence = $stored['highest_sequence'];
        $catalogue = $stored['catalogue'] ?? null;
        if (is_array($catalogue) && $this->isValidLiveCatalogue($catalogue) && $catalogue['sequence'] === $this->highestSequence) {
            $this->catalogue = new CommercialCatalogue($catalogue);
        }
    }

    private function loadSnapshot(): void
    {
        if ($this->snapshotLoaded) {
            return;
        }

        $this->snapshotLoaded = true;
        $path = $this->snapshotPath ?? self::defaultSnapshotPath();

        try {
            $release = $this->installedCoreRelease();
            if ($release === null) {
                return;
            }

            $contents = is_file($path) ? @file_get_contents($path) : false;
            $catalogue = is_string($contents) ? json_decode($contents, true) : null;
            // The issuer signs the exact Composer release tag as part of the snapshot.
            if (is_array($catalogue)
                && ($catalogue['core_version'] ?? null) === $release
                && self::isValid($catalogue, self::SNAPSHOT_MAX_VALIDITY_DAYS)) {
                $this->snapshot = new CommercialCatalogue($catalogue, bundled: true);
            }
        } catch (Throwable) {
            return;
        }
    }

    private function installedCoreRelease(): ?string
    {
        // The aggregate replaces Core at self.version, so Composer reports its version on the aggregate.
        foreach (['capell-app/core', 'capell-app/capell'] as $package) {
            if (! InstalledVersions::isInstalled($package)) {
                continue;
            }

            $version = InstalledVersions::getPrettyVersion($package);
            if ($version !== null) {
                return $version !== '' && ! str_starts_with($version, 'dev-') && ! str_ends_with($version, '-dev')
                    ? $version
                    : null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $catalogue */
    private function signingInput(array $catalogue): string
    {
        return CanonicalizeProjectBuildManifestSigningInputAction::run($catalogue);
    }
}
