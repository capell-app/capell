<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Actions\Marketplace\VerifyMarketplaceIssuerSignatureAction;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Throwable;

/**
 * Verifies the issuer's licence classification during discovery, before database
 * bindings exist. Manifests have no expiry and bind to one Composer release.
 * Verification checks Composer's installed version and reference and any version
 * declared in the installed package's capell.json and composer.json. The owner
 * can edit all of these. If tamper resistance is ever required, a signed SHA-256
 * digest of the release excluding capell-licence.json would bind its contents.
 */
final class SignedPackageLicenceManifest
{
    private const int MAX_BYTES = 64 * 1024;

    private const int CLOCK_SKEW_SECONDS = 300;

    /** @var array<string, PackageLicenceManifestState> */
    private array $states = [];

    public function state(string $composerName): PackageLicenceManifestState
    {
        $name = strtolower($composerName);

        return $this->states[$name] ??= $this->readState($name);
    }

    public function clear(): void
    {
        $this->states = [];
    }

    private function readState(string $name): PackageLicenceManifestState
    {
        try {
            $directory = InstalledVersions::isInstalled($name) ? InstalledVersions::getInstallPath($name) : null;
            $directory ??= resolve(InstalledExtensionRepository::class)->vendorDirectory() . '/' . $name;
            $path = $directory . '/capell-licence.json';
            if (! file_exists($path) && ! is_link($path)) {
                return PackageLicenceManifestState::Absent;
            }

            $contents = is_file($path) ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
            if ($contents === false || strlen($contents) > self::MAX_BYTES) {
                return PackageLicenceManifestState::Invalid;
            }

            $manifest = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)
                || ($manifest['manifest_version'] ?? null) !== 1
                || ($manifest['purpose'] ?? null) !== 'capell-extension-licence-manifest'
                || ! is_string($manifest['composer_name'] ?? null)
                || strtolower($manifest['composer_name']) !== $name
                || ! is_bool($manifest['is_paid'] ?? null)
                || ! is_string($manifest['version'] ?? null)
                || ! is_string($manifest['source_reference'] ?? null)
                || ! is_string($manifest['issued_at'] ?? null)
                || trim($manifest['issued_at']) === ''
                || CarbonImmutable::hasRelativeKeywords($manifest['issued_at'])
                || CarbonImmutable::parse($manifest['issued_at'])->greaterThan(CarbonImmutable::now()->addSeconds(self::CLOCK_SKEW_SECONDS))
                || ! $this->matchesInstalledRelease($name, $manifest['version'], $manifest['source_reference'], $directory)
                || ! VerifyMarketplaceIssuerSignatureAction::run($manifest)) {
                return PackageLicenceManifestState::Invalid;
            }

            return $manifest['is_paid'] ? PackageLicenceManifestState::Paid : PackageLicenceManifestState::Free;
        } catch (Throwable) {
            return PackageLicenceManifestState::Invalid;
        }
    }

    private function matchesInstalledRelease(string $name, string $version, string $reference, string $directory): bool
    {
        $installedVersion = InstalledVersions::getVersion($name);
        $installedReference = InstalledVersions::getReference($name);
        if (! is_string($installedVersion) || ! is_string($installedReference)
            || trim($installedReference) === '' || ! hash_equals($installedReference, $reference)
            || strpbrk($version, " \t\r\n@") !== false) {
            return false;
        }

        $parser = new VersionParser;
        $installedVersion = $parser->normalize($installedVersion);

        if (VersionParser::parseStability($installedVersion) === 'dev' || $installedVersion !== $parser->normalize($version)) {
            return false;
        }

        foreach (['capell.json', 'composer.json'] as $file) {
            $path = $directory . '/' . $file;
            if (! file_exists($path) && ! is_link($path)) {
                continue;
            }

            $contents = is_file($path) ? @file_get_contents($path) : false;
            if ($contents === false) {
                return false;
            }

            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($metadata)) {
                return false;
            }

            if (! array_key_exists('version', $metadata)) {
                continue;
            }

            $declared = $metadata['version'];
            if (! is_string($declared) || $parser->normalize($declared) !== $installedVersion) {
                return false;
            }
        }

        return true;
    }
}
