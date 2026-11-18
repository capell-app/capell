<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Illuminate\Support\Collection;
use JsonException;

/**
 * Catalogue entries Core ships for foundation-vendor packages that a fresh install needs before it
 * can read the Marketplace catalogue, such as the package that declares the default-theme role.
 *
 * A new application has only Core and the installer, and an empty catalogue cache, so without
 * these entries the installer could not find a starter theme and would plan no frontend build.
 * The entries are data, read through the same path as a catalogue response: a live catalogue
 * entry or an installed manifest for the same package replaces them. Only foundation-vendor
 * names are accepted, because a bundled role installs its package unprompted.
 */
final class BundledFoundationCatalogue
{
    private const string PATH = __DIR__ . '/../../../resources/catalogue/foundation-packages.json';

    /**
     * Read on each call rather than memoised: the file is small and only install planning and
     * catalogue listing read it, so no static state outlives a request or an Octane worker cycle.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function entries(): Collection
    {
        return collect(self::read());
    }

    /** Whether Core ships a catalogue entry for this package. */
    public static function contains(string $packageName): bool
    {
        return self::entries()->contains(static fn (array $entry): bool => $entry['name'] === $packageName);
    }

    /**
     * The catalogue rows with every bundled entry the rows do not already list appended, so a
     * live entry always wins over the bundled copy of the same package.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function under(Collection $rows): Collection
    {
        $listed = $rows
            ->map(static fn (mixed $row): mixed => is_array($row) ? ($row['name'] ?? null) : null)
            ->filter(static fn (mixed $name): bool => is_string($name))
            ->flip();

        return $rows
            ->concat(self::entries()->reject(static fn (array $entry): bool => $listed->has($entry['name'])))
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private static function read(): array
    {
        $contents = is_file(self::PATH) ? file_get_contents(self::PATH) : false;

        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $packages = is_array($decoded) ? ($decoded['packages'] ?? null) : null;

        if (! is_array($packages)) {
            return [];
        }

        return array_values(array_filter(
            $packages,
            static fn (mixed $entry): bool => is_array($entry)
                && is_string($entry['name'] ?? null)
                && TrustedCorePackages::isFoundationVendorPackage($entry['name']),
        ));
    }
}
