<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Throwable;

/**
 * Reads which PHP code an installed package claims: its autoload namespaces and the provider
 * classes it registers. A site owner can rename a package in Composer and Capell metadata, but
 * cannot rename the PHP classes inside it, so these identify the code whatever the package is
 * called. Every source the owner can edit is read and combined, because any one of them is
 * enough to load the code.
 */
final class InstalledPackageCodeIdentity
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $installed = null;

    /** @var array<string, array{namespaces: list<string>, prefixes: list<string>, classes: list<string>}> */
    private array $resolved = [];

    public static function namespace(string $prefix): string
    {
        $trimmed = strtolower(trim($prefix, '\\'));

        return $trimmed === '' ? '' : $trimmed . '\\';
    }

    public function clear(): void
    {
        $this->installed = null;
        $this->resolved = [];
    }

    /**
     * PSR-4 namespaces are lower case with one trailing separator ('' is a root mapping); PSR-0
     * prefixes are lower case raw string prefixes; classes are lower case without a leading
     * separator. PHP resolves all of them case-insensitively.
     *
     * @return array{namespaces: list<string>, prefixes: list<string>, classes: list<string>}
     */
    public function for(string $composerName): array
    {
        $name = strtolower($composerName);
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $namespaces = [];
        $prefixes = [];
        $classes = [];
        $vendor = $this->vendorDirectory();
        $entry = $this->installedPackages($vendor)[$name] ?? null;
        $documents = [];
        if (is_array($entry)) {
            $documents[] = $entry;
        }

        $installPath = is_array($entry) && is_string($entry['install-path'] ?? null)
            ? $vendor . '/composer/' . $entry['install-path']
            : $vendor . '/' . $name;
        $composer = $this->readJson($installPath . '/composer.json');
        if ($composer !== null) {
            $documents[] = $composer;
        }

        foreach ($documents as $document) {
            $map = data_get($document, 'autoload.psr-4');
            if (is_array($map)) {
                foreach (array_keys($map) as $prefix) {
                    $namespaces[] = self::namespace((string) $prefix);
                }
            }

            // PSR-0 prefixes are plain string prefixes: "Vendor\\Comm" loads Vendor\Commercial\*,
            // so they keep no added separator.
            $map = data_get($document, 'autoload.psr-0');
            if (is_array($map)) {
                foreach (array_keys($map) as $prefix) {
                    $prefixes[] = strtolower(ltrim((string) $prefix, '\\'));
                }
            }

            foreach (['providers', 'aliases'] as $key) {
                $this->collectClasses(data_get($document, 'extra.laravel.' . $key), $classes);
            }
        }

        $manifest = $this->readJson($installPath . '/capell.json');
        if ($manifest !== null) {
            $this->collectClasses($manifest['providers'] ?? null, $classes);
        }

        return $this->resolved[$name] = [
            'namespaces' => array_values(array_unique($namespaces)),
            'prefixes' => array_values(array_unique($prefixes)),
            'classes' => array_values(array_unique($classes)),
        ];
    }

    /** @param list<string> $classes */
    private function collectClasses(mixed $value, array &$classes): void
    {
        if (is_string($value) && $value !== '') {
            $classes[] = strtolower(ltrim($value, '\\'));

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectClasses($item, $classes);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function installedPackages(string $vendor): array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $decoded = $this->readJson($vendor . '/composer/installed.json');
        // Composer 2 wraps the list in "packages"; Composer 1 wrote the bare list.
        $packages = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : ($decoded ?? []);
        $installed = [];
        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $installed[strtolower($package['name'])] = $package;
            }
        }

        return $this->installed = $installed;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        try {
            $contents = is_file($path) ? file_get_contents($path) : false;
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function vendorDirectory(): string
    {
        try {
            return resolve(InstalledExtensionRepository::class)->vendorDirectory();
        } catch (Throwable) {
            return base_path('vendor');
        }
    }
}
