<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use RuntimeException;

final class MigrationRootRegistry
{
    /** @var array<string, list<array{root: string, tables: array<string, string|list<string>>}>> */
    private array $roots = [];

    /** @param array<string, string|list<string>> $tables Migration name to base table name. */
    public function register(string $package, string $root, array $tables): void
    {
        foreach (array_keys($tables) as $migration) {
            throw_if(basename($migration) !== $migration || ! is_file(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $migration . '.php'), RuntimeException::class, 'Registered migration does not exist: ' . $migration);
        }

        $this->roots[$package][] = ['root' => $root, 'tables' => $tables];
    }

    /**
     * @param  list<string>  $packages
     * @return list<array{root: string, tables: array<string, string|list<string>>}>
     */
    public function forPackages(array $packages): array
    {
        $roots = [];
        foreach ($packages as $package) {
            array_push($roots, ...($this->roots[$package] ?? []));
        }

        return $roots;
    }
}
