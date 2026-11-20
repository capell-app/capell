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
 * Legacy proofs bind the Composer release reference. Version 2 proofs bind
 * the installed content without referring to the commit containing the proof.
 */
final class SignedPackageLicenceManifest
{
    private const int MAX_BYTES = 64 * 1024;

    private const int CLOCK_SKEW_SECONDS = 300;

    /** @var array<string, PackageLicenceManifestState> */
    private array $states = [];

    /** @var array<string, string> */
    private array $contentRoots = [];

    public function state(string $composerName): PackageLicenceManifestState
    {
        $name = strtolower($composerName);

        return $this->states[$name] ??= $this->readState($name);
    }

    public function clear(): void
    {
        $this->states = [];
        $this->contentRoots = [];
    }

    public function verifiedContentRoot(string $composerName): ?string
    {
        $name = strtolower($composerName);
        $this->state($name);

        return $this->contentRoots[$name] ?? null;
    }

    private function readState(string $name): PackageLicenceManifestState
    {
        try {
            $directory = InstalledVersions::isInstalled($name) ? InstalledVersions::getInstallPath($name) : null;
            $directory ??= resolve(InstalledExtensionRepository::class)->vendorDirectory() . '/' . $name;
            $path = $directory . '/' . PackageLicenceContentDigest::MARKER;
            if (! file_exists($path) && ! is_link($path)) {
                return PackageLicenceManifestState::Absent;
            }

            $contents = is_file($path) ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
            if ($contents === false || strlen($contents) > self::MAX_BYTES) {
                return PackageLicenceManifestState::Invalid;
            }

            $manifest = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)
                || ! in_array($manifest['manifest_version'] ?? null, [1, 2], true)
                || ($manifest['purpose'] ?? null) !== 'capell-extension-licence-manifest'
                || ! is_string($manifest['composer_name'] ?? null)
                || strtolower($manifest['composer_name']) !== $name
                || ! is_bool($manifest['is_paid'] ?? null)
                || ! is_string($manifest['version'] ?? null)
                || ! is_string($manifest['issued_at'] ?? null)
                || trim($manifest['issued_at']) === ''
                || CarbonImmutable::hasRelativeKeywords($manifest['issued_at'])
                || CarbonImmutable::parse($manifest['issued_at'])->greaterThan(CarbonImmutable::now()->addSeconds(self::CLOCK_SKEW_SECONDS))
                || ! $this->matchesInstalledVersion($name, $manifest['version'])
                || ! VerifyMarketplaceIssuerSignatureAction::run($manifest)) {
                return PackageLicenceManifestState::Invalid;
            }

            if ($manifest['manifest_version'] === 1) {
                if (! $this->matchesInstalledReference($name, $manifest['source_reference'] ?? null)) {
                    return PackageLicenceManifestState::Invalid;
                }
            } else {
                $root = realpath($directory);
                if ($root === false || is_link($path) || ! $this->matchesInstalledContent($directory, $manifest)) {
                    return PackageLicenceManifestState::Invalid;
                }

                $this->contentRoots[$name] = $root;
            }

            return $manifest['is_paid'] ? PackageLicenceManifestState::Paid : PackageLicenceManifestState::Free;
        } catch (Throwable) {
            return PackageLicenceManifestState::Invalid;
        }
    }

    private function matchesInstalledVersion(string $name, string $version): bool
    {
        $installedVersion = InstalledVersions::getVersion($name);
        if (! is_string($installedVersion) || strpbrk($version, " \t\r\n@") !== false) {
            return false;
        }

        $parser = new VersionParser;
        $installedVersion = $parser->normalize($installedVersion);

        return VersionParser::parseStability($installedVersion) !== 'dev'
            && $installedVersion === $parser->normalize($version);
    }

    private function matchesInstalledReference(string $name, mixed $reference): bool
    {
        $installed = InstalledVersions::getReference($name);

        return is_string($installed) && trim($installed) !== ''
            && is_string($reference) && hash_equals($installed, $reference);
    }

    /** @param array<mixed> $manifest */
    private function matchesInstalledContent(string $directory, array $manifest): bool
    {
        $source = $manifest['source_tree_sha256'] ?? null;
        $dist = $manifest['dist_tree_sha256'] ?? null;
        if (! is_string($source) || ! is_string($dist)
            || preg_match('/\A[a-f0-9]{64}\z/', $source) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $dist) !== 1) {
            return false;
        }

        $actual = new PackageLicenceContentDigest()->calculate($directory);

        return $actual !== null && (hash_equals($source, $actual) || hash_equals($dist, $actual));
    }
}
