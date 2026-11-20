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
 * Verification checks Composer's installed version and reference. The owner can
 * edit that metadata. A signed SHA-256 digest of the release excluding
 * capell-licence.json would bind its contents.
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
                || ! $this->matchesInstalledRelease($name, $manifest['version'], $manifest['source_reference'])
                || ! VerifyMarketplaceIssuerSignatureAction::run($manifest)) {
                return PackageLicenceManifestState::Invalid;
            }

            return $manifest['is_paid'] ? PackageLicenceManifestState::Paid : PackageLicenceManifestState::Free;
        } catch (Throwable) {
            return PackageLicenceManifestState::Invalid;
        }
    }

    private function matchesInstalledRelease(string $name, string $version, string $reference): bool
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

        return VersionParser::parseStability($installedVersion) !== 'dev'
            && $installedVersion === $parser->normalize($version);
    }
}
