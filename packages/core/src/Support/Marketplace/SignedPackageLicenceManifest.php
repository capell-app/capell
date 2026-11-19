<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Actions\Marketplace\VerifyMarketplaceIssuerSignatureAction;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Throwable;

/**
 * Verifies the issuer's licence classification during discovery, before database
 * bindings exist. Manifests have no expiry. When a package becomes paid at 2.0,
 * the paid manifest must cover 2.0 and every free manifest must exclude it.
 */
final class SignedPackageLicenceManifest
{
    private const int MAX_BYTES = 64 * 1024;

    private const int CLOCK_SKEW_SECONDS = 300;

    /** @var array<string, bool|null> */
    private array $classifications = [];

    public function classification(string $composerName): ?bool
    {
        $name = strtolower($composerName);
        if (! array_key_exists($name, $this->classifications)) {
            $this->classifications[$name] = $this->readClassification($name);
        }

        return $this->classifications[$name];
    }

    public function clear(): void
    {
        $this->classifications = [];
    }

    private function readClassification(string $name): ?bool
    {
        try {
            $version = InstalledVersions::getVersion($name);
            if ($version === null || VersionParser::parseStability($version) === 'dev') {
                return null;
            }

            $directory = InstalledVersions::getInstallPath($name)
                ?? resolve(InstalledExtensionRepository::class)->vendorDirectory() . '/' . $name;
            $path = $directory . '/capell-licence.json';
            $contents = is_file($path) ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
            if ($contents === false || strlen($contents) > self::MAX_BYTES) {
                return null;
            }

            $manifest = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)
                || ($manifest['manifest_version'] ?? null) !== 1
                || ($manifest['purpose'] ?? null) !== 'capell-extension-licence-manifest'
                || ! is_string($manifest['composer_name'] ?? null)
                || strtolower($manifest['composer_name']) !== $name
                || ! is_bool($manifest['is_paid'] ?? null)
                || ! is_string($manifest['version_constraint'] ?? null)
                || trim($manifest['version_constraint']) === ''
                || ! is_string($manifest['issued_at'] ?? null)
                || trim($manifest['issued_at']) === ''
                || CarbonImmutable::hasRelativeKeywords($manifest['issued_at'])
                || CarbonImmutable::parse($manifest['issued_at'])->greaterThan(CarbonImmutable::now()->addSeconds(self::CLOCK_SKEW_SECONDS))
                || ! Semver::satisfies($version, $manifest['version_constraint'])
                || ! VerifyMarketplaceIssuerSignatureAction::run($manifest)) {
                return null;
            }

            return $manifest['is_paid'];
        } catch (Throwable) {
            return null;
        }
    }
}
