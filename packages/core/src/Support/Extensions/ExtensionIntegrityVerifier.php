<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData as Issue;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Carbon\CarbonImmutable;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Throwable;

final class ExtensionIntegrityVerifier
{
    public function __construct(private readonly string $vendorDir, private readonly ManifestValidator $validator) {}

    public function verify(string $composerName, ?string $expectedVersionConstraint = null, ?string $previousVersion = null, ?string $expectedSlug = null, ?string $expectedDistSha = null, ?string $releaseChecksumVersion = null, bool $dependency = false, ?string $expectedContentHash = null): ExtensionVerificationReportData
    {
        $failures = [];
        $warnings = [];
        $omitted = 0;
        $comparisons = [];
        $contentHash = null;
        $add = static function (string $code, ?string $reason = null) use (&$failures, &$omitted, $composerName): void {
            if (count($failures) >= 50) {
                $omitted++;

                return;
            }

            $description = Issue::describe($code, $composerName, $reason);
            $failures[] = new Issue($code, $description['message'], $description['next_action']);
        };
        $installed = $this->readJson($this->vendorDir . '/composer/installed.json');
        $packages = $installed['packages'] ?? $installed;
        $packages = is_array($packages) ? $packages : [];

        $package = array_find($packages, fn ($entry): bool => is_array($entry) && ($entry['name'] ?? null) === $composerName);

        $version = is_string($package['version'] ?? null) ? $package['version'] : null;
        $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
        $sha = is_string($dist['shasum'] ?? null) && $dist['shasum'] !== '' ? $dist['shasum'] : null;
        $reference = is_string($dist['reference'] ?? null) ? $dist['reference'] : null;
        $host = is_string($dist['url'] ?? null) ? parse_url($dist['url'], PHP_URL_HOST) : null;
        $host = is_string($host) ? $host : null;

        $installPath = is_string($package['install-path'] ?? null) ? $this->vendorDir . '/composer/' . $package['install-path'] : null;
        $realPackage = $installPath !== null ? realpath($installPath) : false;

        if ($package === null) {
            $add(Issue::NOT_INSTALLED);
        } else {
            if ($expectedVersionConstraint !== null) {
                try {
                    $satisfies = $version !== null && Semver::satisfies($version, $expectedVersionConstraint);
                } catch (Throwable) {
                    $satisfies = false;
                }

                if (! $satisfies) {
                    $add(Issue::VERSION_OUTSIDE_CONSTRAINT);
                }
            }

            if ($previousVersion !== null) {
                try {
                    $upgrade = $version !== null && Comparator::greaterThan($version, $previousVersion);
                } catch (Throwable) {
                    $upgrade = false;
                }

                if (! $upgrade) {
                    $add(Issue::NOT_AN_UPGRADE);
                }
            }

            $trusted = TrustedCorePackages::contains($composerName);
            $require = is_array($package['require'] ?? null) ? $package['require'] : [];
            $requireDev = is_array($package['require-dev'] ?? null) ? $package['require-dev'] : [];
            if (! $trusted && ($dependency || ! config('capell.extensions.verification.allow_composer_plugins', false))
                && (($package['type'] ?? null) === 'composer-plugin' || array_key_exists('composer-plugin-api', $require) || array_key_exists('composer-plugin-api', $requireDev))) {
                $add(Issue::COMPOSER_PLUGIN);
            }

            $autoload = is_array($package['autoload'] ?? null) ? $package['autoload'] : [];
            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
                $entries = $autoload[$kind] ?? [];
                if (! is_array($entries)) {
                    $add(Issue::AUTOLOAD_ESCAPES_PACKAGE);

                    continue;
                }

                foreach ($entries as $paths) {
                    foreach (is_array($paths) ? $paths : [$paths] as $path) {
                        if (! is_string($path) || ! $this->safePath($realPackage, $path)) {
                            $add(Issue::AUTOLOAD_ESCAPES_PACKAGE);
                        }
                    }
                }
            }

            if (! $dependency) {
                $manifestPath = $realPackage !== false ? $realPackage . '/capell.json' : null;
                if ($manifestPath === null || ! is_file($manifestPath)) {
                    $add(Issue::MANIFEST_MISSING);
                } else {
                    $manifest = $this->readJson($manifestPath);
                    try {
                        // Use installed metadata for the namespace boundary; never load classes.
                        $this->validator->validateStructure($manifest, ['autoload' => $autoload]);
                    } catch (Throwable $exception) {
                        $add(Issue::MANIFEST_INVALID, $exception->getMessage());
                    }

                    if (! $trusted && (($manifest['name'] ?? null) !== $composerName || ($expectedSlug !== null && ($manifest['slug'] ?? null) !== $expectedSlug))) {
                        $add(Issue::MANIFEST_IDENTITY_MISMATCH);
                    }

                    foreach ($this->manifestClasses($manifest) as $class) {
                        if (! $this->classFileExists($class, $autoload['psr-4'] ?? [], $realPackage)) {
                            $add(Issue::PROVIDER_FILE_MISSING);
                        }
                    }
                }
            }

            if (($expectedDistSha !== null || $expectedContentHash !== null) && $releaseChecksumVersion !== null && $version !== $releaseChecksumVersion) {
                if (count($failures) >= 50) {
                    $omitted++;
                } else {
                    $description = Issue::describe(Issue::CHECKSUM_NOT_FOR_INSTALLED_RELEASE, $composerName);
                    $warnings[] = new Issue(Issue::CHECKSUM_NOT_FOR_INSTALLED_RELEASE, $description['message'], $description['next_action']);
                }
            } else {
                if ($expectedDistSha !== null) {
                    $comparisons[] = 'dist_checksum';
                    if ($sha === null) {
                        $add(Issue::CHECKSUM_MISSING);
                    } elseif (! hash_equals($expectedDistSha, $sha)) {
                        $add(Issue::CHECKSUM_MISMATCH);
                    }
                }

                if ($expectedContentHash !== null && $failures === []) {
                    $comparisons[] = 'content_sha256';
                    try {
                        $contentHash = $realPackage === false ? null : HashExtensionContentsAction::run($realPackage);
                        if ($contentHash === null || ! hash_equals(strtolower($expectedContentHash), $contentHash)) {
                            $add(Issue::CONTENT_HASH_MISMATCH);
                        }
                    } catch (Throwable) {
                        $add(Issue::CONTENT_HASH_UNREADABLE);
                    }
                }
            }
        }

        return new ExtensionVerificationReportData($composerName, $version, $expectedVersionConstraint, $previousVersion, $failures === [], $failures, $warnings, CarbonImmutable::now(), $sha, $reference, $host, $realPackage === false ? null : $realPackage, $omitted, $comparisons, $contentHash);
    }

    public function verifyDependency(string $composerName): ExtensionVerificationReportData
    {
        return $this->verify($composerName, dependency: true);
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        $decoded = $contents !== false ? json_decode($contents, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function safePath(string|false $root, string $path): bool
    {
        if ($root === false || str_contains($path, "\0") || preg_match('~^(?:[\\\\/]|[a-zA-Z]:|[a-zA-Z][a-zA-Z0-9+.-]*:)~', $path) || in_array('..', preg_split('~[\\\\/]~', $path) ?: [], true)) {
            return false;
        }

        $resolved = realpath($root . '/' . $path);

        return $resolved !== false && ($resolved === $root || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR));
    }

    /** @param array<mixed> $manifest
     * @return list<string>
     */
    private function manifestClasses(array $manifest): array
    {
        $classes = [];
        foreach (is_array($manifest['providers'] ?? null) ? $manifest['providers'] : [] as $bucket) {
            foreach (is_array($bucket) ? $bucket : [] as $class) {
                if (is_string($class)) {
                    $classes[] = $class;
                }
            }
        }

        foreach (['contributes', 'healthChecks'] as $key) {
            foreach (is_array($manifest[$key] ?? null) ? $manifest[$key] : [] as $entry) {
                if (is_array($entry) && is_string($entry['class'] ?? null)) {
                    $classes[] = $entry['class'];
                }
            }
        }

        return array_values(array_unique($classes));
    }

    private function classFileExists(string $class, mixed $prefixes, string|false $root): bool
    {
        if (! is_array($prefixes)) {
            return false;
        }

        foreach ($prefixes as $prefix => $paths) {
            if (! is_string($prefix)) {
                continue;
            }

            if ($prefix === '') {
                continue;
            }

            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                if (is_string($path) && $this->safePath($root, $path) && $this->safePath($root, rtrim($path, '/') . '/' . $relative) && is_file($root . '/' . $path . '/' . $relative)) {
                    return true;
                }
            }
        }

        return false;
    }
}
