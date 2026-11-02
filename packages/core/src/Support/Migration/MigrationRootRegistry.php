<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use Closure;
use RuntimeException;

final class MigrationRootRegistry
{
    /** @var array<string, list<array{root: string, tables: array<string, string|list<string>>}>> */
    private array $roots = [];

    /** @var array<string, list<Closure(): string>> */
    private array $scannedRoots = [];

    /** @param array<string, string|list<string>> $tables Migration name to base table name. */
    public function register(string $package, string $root, array $tables): void
    {
        foreach (array_keys($tables) as $migration) {
            throw_if(basename($migration) !== $migration || ! is_file(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $migration . '.php'), RuntimeException::class, 'Registered migration does not exist: ' . $migration);
        }

        $this->roots[$package][] = ['root' => $root, 'tables' => $tables];
    }

    /**
     * Register a migration directory whose created tables are read from the
     * migrations themselves, so the list can never drift from what the package
     * actually creates. The directory is resolved and scanned only when the
     * installer asks for collisions, never on an ordinary request.
     *
     * @param  Closure(): string  $root
     */
    public function registerScanned(string $package, Closure $root): void
    {
        $this->scannedRoots[$package][] = $root;
    }

    /**
     * Base table names created by each migration in a directory, read from
     * Schema::create(TableName::resolve('base')) calls.
     *
     * @return array<string, list<string>>
     */
    public static function scan(string $root): array
    {
        $tables = [];
        foreach (glob(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
            $source = file_get_contents($path);
            throw_if($source === false, RuntimeException::class, 'Cannot read migration ' . $path);
            preg_match_all("~Schema::create\\(\\s*TableName::resolve\\(\\s*(?:\\(string\\)\\s*)?(?:(?:Config::(?:string|get)|config)\\([^,]+,\\s*)?'([a-z][a-z0-9_]*)'~", $source, $matches);
            if ($matches[1] !== []) {
                $tables[basename($path, '.php')] = array_values(array_unique($matches[1]));
            }
        }

        return $tables;
    }

    /**
     * @param  list<string>  $packages
     * @return list<array{root: string, tables: array<string, string|list<string>>}>
     */
    public function forPackages(array $packages): array
    {
        $roots = [];
        foreach ($packages as $package) {
            foreach ($this->scannedRoots[$package] ?? [] as $index => $resolveRoot) {
                $root = $resolveRoot();
                $this->roots[$package][] = ['root' => $root, 'tables' => self::scan($root)];
                unset($this->scannedRoots[$package][$index]);
            }

            array_push($roots, ...($this->roots[$package] ?? []));
        }

        return $roots;
    }
}
