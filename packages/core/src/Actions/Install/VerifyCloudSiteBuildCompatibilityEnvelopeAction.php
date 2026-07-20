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
            'core_version' => $this->installedVersion('capell-app/core'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'filament_version' => $this->installedVersion('filament/filament'),
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
            if (! hash_equals(ltrim($this->installedVersion($name), 'v'), ltrim($release['version'], 'v'))) {
                $this->fail();
            }

            if ($name === 'capell-app/core') {
                $reference = InstalledVersions::getReference($name);
                if (! is_string($reference) || ! hash_equals('composer-reference:' . $reference, $release['release_identity'])) {
                    $this->fail();
                }

                continue;
            }

            $reference = InstalledVersions::getReference($name);
            $installPath = InstalledVersions::getInstallPath($name);
            $manifestPath = is_string($installPath) ? $installPath . DIRECTORY_SEPARATOR . 'composer.json' : null;
            $manifestSha256 = is_string($manifestPath) && is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;
            if (! is_string($reference)
                || ! hash_equals($release['source_reference'], $reference)
                || preg_match('/\A[a-f0-9]{64}\z/', $release['artifact_sha256']) !== 1
                || ! is_string($manifestSha256)
                || ! hash_equals($release['install_manifest_sha256'], $manifestSha256)
                || ! is_string($installPath)
                || ! is_dir($installPath)
                || ! is_file($manifestPath)) {
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

    private function installedVersion(string $package): string
    {
        $version = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        if (! is_string($version) || trim($version) === '') {
            $this->fail();
        }

        return $version;
    }

    /** @param array<string, mixed> $compatibility @param array<string, string> $observed */
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
            ? array_map('trim', explode('|', strtolower($compatibility['platform'])))
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
