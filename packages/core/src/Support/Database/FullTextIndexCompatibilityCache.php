<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Stringable;

final class FullTextIndexCompatibilityCache
{
    /**
     * @var array<string, array{
     *     connection: string,
     *     index: string,
     *     compatible: bool,
     *     used: int
     * }>
     */
    private array $entries = [];

    private int $sequence = 0;

    public function __construct(private readonly int $maxEntries = 256)
    {
        throw_if($maxEntries < 1, InvalidArgumentException::class, 'The full-text compatibility cache requires a positive entry limit.');
    }

    /**
     * @param  Closure(): bool  $inspect
     */
    public function remember(
        Connection $connection,
        DatabaseIndexDefinition $index,
        Closure $inspect,
    ): bool {
        $connectionKey = $this->connectionKey($connection);
        $indexKey = $this->indexKey($index);
        $key = $connectionKey . ':' . $indexKey;

        if (array_key_exists($key, $this->entries)) {
            $this->entries[$key]['used'] = ++$this->sequence;

            return $this->entries[$key]['compatible'];
        }

        $compatible = $inspect();
        $this->entries[$key] = [
            'connection' => $connectionKey,
            'index' => $indexKey,
            'compatible' => $compatible,
            'used' => ++$this->sequence,
        ];

        $this->evictLeastRecentlyUsedEntry();

        return $compatible;
    }

    public function forget(
        Connection $connection,
        ?DatabaseIndexDefinition $index = null,
    ): void {
        $connectionKey = $this->connectionKey($connection);
        $indexKey = $index instanceof DatabaseIndexDefinition
            ? $this->indexKey($index)
            : null;

        foreach ($this->entries as $key => $entry) {
            if ($entry['connection'] !== $connectionKey) {
                continue;
            }

            if ($indexKey === null || $entry['index'] === $indexKey) {
                unset($this->entries[$key]);
            }
        }
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->sequence = 0;
    }

    private function evictLeastRecentlyUsedEntry(): void
    {
        if (count($this->entries) <= $this->maxEntries) {
            return;
        }

        $leastRecentlyUsedKey = null;
        $leastRecentSequence = PHP_INT_MAX;

        foreach ($this->entries as $key => $entry) {
            if ($entry['used'] < $leastRecentSequence) {
                $leastRecentlyUsedKey = $key;
                $leastRecentSequence = $entry['used'];
            }
        }

        if (is_string($leastRecentlyUsedKey)) {
            unset($this->entries[$leastRecentlyUsedKey]);
        }
    }

    private function connectionKey(Connection $connection): string
    {
        return $this->hash([
            'name' => $connection->getName(),
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'unix_socket' => $connection->getConfig('unix_socket'),
            'prefix' => $connection->getTablePrefix(),
            'read_host' => $connection->getConfig('read.host'),
            'read_port' => $connection->getConfig('read.port'),
            'read_socket' => $connection->getConfig('read.unix_socket'),
            'write_host' => $connection->getConfig('write.host'),
            'write_port' => $connection->getConfig('write.port'),
            'write_socket' => $connection->getConfig('write.unix_socket'),
        ]);
    }

    private function indexKey(DatabaseIndexDefinition $index): string
    {
        $prefixLengths = $index->prefixLengths;
        ksort($prefixLengths);

        return $this->hash([
            'table' => $index->table,
            'name' => $index->name,
            'columns' => $index->columns,
            'prefix_lengths' => $prefixLengths,
            'unique' => $index->unique,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function hash(array $value): string
    {
        return hash('sha256', serialize($this->normalize($value)));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value instanceof Stringable ? (string) $value : $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
