<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Support\Extensions\CapellExtensionApi;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use RuntimeException;

final class VerifyCloudSiteBuildCompatibilityEnvelopeAction
{
    /** @param array<string, mixed>|null $evidence */
    public function handle(string $registrationToken, ?array $evidence, string $installPackages): void
    {
        if (! is_array($evidence) || ! is_bool($evidence['required'] ?? null)) {
            $this->fail();
        }

        if ($evidence['required'] === false) {
            return;
        }

        $payload = $evidence['payload'] ?? null;
        $signature = $evidence['signature'] ?? null;

        if ($registrationToken === '' || ! is_string($payload) || $payload === '' || ! is_string($signature) || $signature === '') {
            $this->fail();
        }

        $expectedSignature = hash_hmac('sha256', $payload, $registrationToken);
        if (! hash_equals($expectedSignature, $signature)) {
            $this->fail();
        }

        $decoded = base64_decode($payload, true);
        $envelope = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($envelope)
            || ($envelope['schema_version'] ?? null) !== 1
            || ! is_array($envelope['target'] ?? null)
            || ! is_array($envelope['package_releases'] ?? null)
            || ! array_is_list($envelope['package_releases'])) {
            $this->fail();
        }

        $target = $envelope['target'];
        $observed = [
            'capell_api_version' => CapellExtensionApi::CURRENT_VERSION,
            'core_version' => $this->installedPackageEvidence('capell-app/core')['version'],
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'filament_version' => $this->installedPackageEvidence('filament/filament')['version'],
            'platform' => strtolower(PHP_OS_FAMILY),
        ];
        foreach ($observed as $fact => $version) {
            if (! is_string($target[$fact] ?? null) || ! hash_equals(ltrim($version, 'v'), ltrim($target[$fact], 'v'))) {
                $this->fail();
            }
        }

        $expectedPackages = array_values(array_filter(explode(',', $installPackages)));
        sort($expectedPackages);
        $envelopePackages = [];
        foreach ($envelope['package_releases'] as $release) {
            if (! is_array($release)
                || ! is_string($release['name'] ?? null)
                || ! is_string($release['version'] ?? null)
                || ! is_string($release['release_identity'] ?? null)
                || ! is_string($release['source_reference'] ?? null)
                || ! is_string($release['artifact_sha256'] ?? null)
                || ! is_string($release['install_manifest_sha256'] ?? null)
                || ! is_array($release['compatibility'] ?? null)) {
                $this->fail();
            }

            $name = $release['name'];
            $installedPackage = $this->installedPackageEvidence($name);
            if (! hash_equals(ltrim($installedPackage['version'], 'v'), ltrim($release['version'], 'v'))) {
                $this->fail();
            }

            if (! hash_equals($release['source_reference'], $installedPackage['reference'])
                || ! hash_equals($release['install_manifest_sha256'], $installedPackage['manifestSha256'])) {
                $this->fail();
            }

            if ($name === 'capell-app/core') {
                if (! hash_equals('composer-reference:' . $installedPackage['reference'], $release['release_identity'])) {
                    $this->fail();
                }

                continue;
            }

            if (preg_match('/\A[a-f0-9]{64}\z/', $release['artifact_sha256']) !== 1) {
                $this->fail();
            }

            $envelopePackages[] = $name;
            $this->verifyCompatibility($release['compatibility'], $observed);
        }

        sort($envelopePackages);
        if ($envelopePackages !== $expectedPackages) {
            $this->fail();
        }
    }

    /** @return array{version: string, reference: string, manifestSha256: string} */
    private function installedPackageEvidence(string $package): array
    {
        if (InstalledVersions::isInstalled($package)) {
            $version = InstalledVersions::getPrettyVersion($package);
            $reference = InstalledVersions::getReference($package);
            $installPath = InstalledVersions::getInstallPath($package);

            if (is_string($version) && trim($version) !== ''
                && is_string($reference) && trim($reference) !== ''
                && is_string($installPath) && is_dir($installPath)) {
                return $this->verifiedPackageEvidence($version, $reference, $installPath);
            }
        }

        $root = InstalledVersions::getRootPackage();
        $rootInstallPath = $root['install_path'] ?? null;
        $embeddedInstallPath = is_string($rootInstallPath)
            ? $rootInstallPath . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $package))
            : null;
        $embeddedManifestPath = is_string($embeddedInstallPath)
            ? $embeddedInstallPath . DIRECTORY_SEPARATOR . 'composer.json'
            : null;
        $embeddedManifest = is_string($embeddedManifestPath) && is_file($embeddedManifestPath)
            ? json_decode((string) file_get_contents($embeddedManifestPath), true)
            : null;

        if (($root['name'] ?? null) !== 'capell-app/capell'
            || ! is_array($embeddedManifest)
            || ($embeddedManifest['name'] ?? null) !== $package) {
            $this->fail();
        }

        return $this->verifiedPackageEvidence(
            $root['pretty_version'] ?? null,
            $root['reference'] ?? null,
            $embeddedInstallPath,
        );
    }

    /** @return array{version: string, reference: string, manifestSha256: string} */
    private function verifiedPackageEvidence(mixed $version, mixed $reference, mixed $installPath): array
    {
        $manifestPath = is_string($installPath) ? $installPath . DIRECTORY_SEPARATOR . 'composer.json' : null;
        $manifestSha256 = is_string($manifestPath) && is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;

        if (! is_string($version) || trim($version) === ''
            || ! is_string($reference) || trim($reference) === ''
            || ! is_string($installPath) || ! is_dir($installPath)
            || ! is_string($manifestSha256)) {
            $this->fail();
        }

        return [
            'version' => $version,
            'reference' => $reference,
            'manifestSha256' => $manifestSha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $compatibility
     * @param  array<string, string>  $observed
     */
    private function verifyCompatibility(array $compatibility, array $observed): void
    {
        $constraints = [
            'capell_api' => 'capell_api_version',
            'core' => 'core_version',
            'php' => 'php_version',
            'laravel' => 'laravel_version',
            'filament' => 'filament_version',
        ];
        foreach ($constraints as $constraint => $fact) {
            if (! is_string($compatibility[$constraint] ?? null)
                || ! Semver::satisfies(ltrim($observed[$fact], 'v'), $compatibility[$constraint])) {
                $this->fail();
            }
        }

        $platforms = is_string($compatibility['platform'] ?? null)
            ? array_map(trim(...), explode('|', strtolower($compatibility['platform'])))
            : [];
        if (! in_array($observed['platform'], $platforms, true)) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw new RuntimeException('Cloud Site Builder compatibility evidence is missing, invalid, or incompatible with this target.');
    }
}
