<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Actions\Marketplace\VerifyMarketplaceIssuerSignatureAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Holds the issuer-signed paid/free classification delivered by the heartbeat or an offline
 * import. Every read re-verifies the signature, and the highest accepted sequence is kept so a
 * replayed older catalogue (from before a package became paid) is refused.
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

    private const int CLOCK_SKEW_SECONDS = 300;

    private bool $loaded = false;

    /** @var array<string, mixed>|null */
    private ?array $catalogue = null;

    private int $highestSequence = 0;

    private readonly Filesystem $files;

    // Built without the container: Laravel package discovery consults the store before
    // the filesystem binding exists.
    public function __construct()
    {
        $this->files = new Filesystem;
    }

    /** @param array<string, mixed> $catalogue */
    public static function isValid(array $catalogue): bool
    {
        if (($catalogue['catalogue_version'] ?? null) !== self::CATALOGUE_VERSION
            || ($catalogue['purpose'] ?? null) !== self::PURPOSE
            || ! is_int($catalogue['sequence'] ?? null) || $catalogue['sequence'] < 1
            || ! is_array($catalogue['packages'] ?? null)
            || ! is_string($catalogue['issued_at'] ?? null) || ! is_string($catalogue['valid_until'] ?? null)
            || ! self::withinValidityWindow($catalogue['issued_at'], $catalogue['valid_until'])) {
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
        $this->catalogue = null;
        $this->highestSequence = 0;
    }

    /** @return array<string, mixed>|null a verified catalogue, or null when none is held */
    public function current(): ?array
    {
        $this->load();

        return $this->catalogue;
    }

    /** Null means the package is not listed; it never means the catalogue is missing. */
    public function classification(string $composerName): ?bool
    {
        $catalogue = $this->current();
        if ($catalogue === null) {
            return null;
        }

        $name = strtolower($composerName);
        foreach ($catalogue['packages'] as $entry) {
            if (strtolower($entry['composer_name']) === $name) {
                return $entry['is_paid'];
            }
        }

        return null;
    }

    public function isListed(string $composerName): bool
    {
        return $this->classification($composerName) !== null;
    }

    /** @param array<string, mixed> $catalogue */
    public function accept(array $catalogue): bool
    {
        if (! self::isValid($catalogue)) {
            return false;
        }

        $this->load();
        $sequence = $catalogue['sequence'];
        if ($sequence < $this->highestSequence) {
            return false;
        }

        if ($sequence === $this->highestSequence && $this->catalogue !== null) {
            // The same sequence must be the same signed document, never a second variant.
            return $this->signingInput($catalogue) === $this->signingInput($this->catalogue);
        }

        $directory = dirname($this->path());
        $this->files->ensureDirectoryExists($directory);
        $temporary = $directory . '/.commercial-catalogue-' . bin2hex(random_bytes(8)) . '.json';
        $this->files->put($temporary, json_encode(['highest_sequence' => $sequence, 'catalogue' => $catalogue], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->files->move($temporary, $this->path());

        $this->catalogue = $catalogue;
        $this->highestSequence = $sequence;
        $this->loaded = true;

        return true;
    }

    private static function withinValidityWindow(string $issuedAt, string $validUntil): bool
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
            && $until->lessThanOrEqualTo($issued->addDays(self::MAX_VALIDITY_DAYS))
            && $now->lessThan($until);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->catalogue = null;
        $this->highestSequence = 0;

        try {
            $contents = $this->files->exists($this->path()) ? $this->files->get($this->path()) : null;
            $stored = is_string($contents) ? json_decode($contents, true) : null;
        } catch (Throwable) {
            return;
        }

        if (! is_array($stored) || ! is_int($stored['highest_sequence'] ?? null) || ! is_array($stored['catalogue'] ?? null)) {
            return;
        }

        $this->highestSequence = $stored['highest_sequence'];
        /** @var array<string, mixed> $catalogue */
        $catalogue = $stored['catalogue'];
        if (self::isValid($catalogue) && $catalogue['sequence'] === $this->highestSequence) {
            $this->catalogue = $catalogue;
        }
    }

    /** @param array<string, mixed> $catalogue */
    private function signingInput(array $catalogue): string
    {
        return CanonicalizeProjectBuildManifestSigningInputAction::run($catalogue);
    }
}
