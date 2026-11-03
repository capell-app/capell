<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Tracks which nodes of a multi-node deployment are alive, through the shared
 * cache store, so a node-local remediation can wait for every node to apply it.
 */
final class SecurityNodeRegistry
{
    private const string INDEX_KEY = 'capell:security:nodes';

    public function enabled(): bool
    {
        return config('capell.multi_node', false) === true;
    }

    public function nodeId(): string
    {
        $configured = config('capell.node_id');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
    }

    public function register(): void
    {
        $store = $this->store();
        $nodeId = $this->nodeId();
        $hostname = gethostname();
        $payload = ['node_id' => $nodeId, 'hostname' => is_string($hostname) ? $hostname : null, 'last_seen_at' => CarbonImmutable::now()->toIso8601String()];
        throw_unless($store->put(self::INDEX_KEY . ':' . $nodeId, $payload, $this->ttlSeconds()), RuntimeException::class, 'Unable to register this node in the shared cache.');

        $this->synchronise($store, function () use ($store, $nodeId): void {
            $index = $this->index($store);
            if (! in_array($nodeId, $index, true)) {
                $index[] = $nodeId;
                throw_unless($store->forever(self::INDEX_KEY, $index), RuntimeException::class, 'Unable to register this node in the shared cache.');
            }
        });
    }

    /** @return list<string> Nodes seen within the configured lifetime, sorted. */
    public function activeNodes(): array
    {
        $store = $this->store();
        $cutoff = CarbonImmutable::now()->subMinutes($this->ttlMinutes());
        $active = [];
        foreach ($this->index($store) as $nodeId) {
            $entry = $store->get(self::INDEX_KEY . ':' . $nodeId);
            $seenAt = is_array($entry) && is_string($entry['last_seen_at'] ?? null) ? CarbonImmutable::parse($entry['last_seen_at']) : null;
            if ($seenAt instanceof CarbonImmutable && $seenAt->gte($cutoff)) {
                $active[] = $nodeId;
            }
        }

        sort($active);

        return $active;
    }

    private function store(): Repository
    {
        $name = config('cache.default');
        $driver = is_string($name) ? config('cache.stores.' . $name . '.driver') : null;
        throw_if(! is_string($driver) || in_array($driver, ['array', 'file', 'null'], true), RuntimeException::class, 'Multi-node remediation requires a shared cache store.');

        return Cache::store($name);
    }

    /** @return list<string> */
    private function index(Repository $store): array
    {
        $index = $store->get(self::INDEX_KEY);

        return is_array($index) ? array_values(array_filter($index, is_string(...))) : [];
    }

    /** @param callable(): void $operation */
    private function synchronise(Repository $store, callable $operation): void
    {
        $lockStore = $store->getStore();
        throw_unless($lockStore instanceof LockProvider, RuntimeException::class, 'The shared cache store cannot hold the node registry lock.');

        try {
            $lockStore->lock(self::INDEX_KEY . ':lock', 10)->block(5, $operation);
        } catch (LockTimeoutException $lockTimeoutException) {
            throw new RuntimeException('Unable to acquire the node registry lock.', 0, $lockTimeoutException);
        }
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('capell.security.node_ttl_minutes', 30));
    }

    private function ttlSeconds(): int
    {
        return $this->ttlMinutes() * 60;
    }
}
