<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Models\SecurityRemediationRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Tracks which nodes of a multi-node deployment are alive, through the shared
 * cache store, so a node-local remediation can wait for every node to apply it.
 */
final class SecurityNodeRegistry
{
    private const string INDEX_KEY = 'capell:security:nodes';

    public static function validNodeList(mixed $nodes, bool $allowEmpty = false): bool
    {
        return self::parseNodeList($nodes, $allowEmpty) !== null;
    }

    /**
     * @return list<string>|null the validated node identities, or null when the value is malformed
     */
    public static function parseNodeList(mixed $nodes, bool $allowEmpty = false): ?array
    {
        if (! is_array($nodes) || ! array_is_list($nodes) || (! $allowEmpty && $nodes === [])) {
            return null;
        }

        $validated = [];
        foreach ($nodes as $node) {
            if (! is_string($node) || $node === '' || strlen($node) > 255 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $node) !== 1) {
                return null;
            }

            $validated[] = $node;
        }

        return count(array_unique($validated)) === count($validated) ? $validated : null;
    }

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
        throw_unless(self::validNodeList([$nodeId]), RuntimeException::class, 'Invalid node identity.');
        $hostname = gethostname();
        throw_unless(is_string($hostname) && $hostname !== '', RuntimeException::class, 'Invalid hostname.');

        $this->synchronise($store, function () use ($store, $nodeId, $hostname): void {
            $index = $this->index($store, true);
            throw_if($this->retired($store, $nodeId), RuntimeException::class, 'This node has been retired.');
            $entry = $store->get(self::INDEX_KEY . ':' . $nodeId);
            if (in_array($nodeId, $index, true)) {
                $this->validateEntry($entry, $nodeId);
                $live = CarbonImmutable::parse($entry['last_seen_at'])->gte(CarbonImmutable::now()->subMinutes($this->ttlMinutes()));
                if (($entry['collision'] ?? false) === true || ($live && $entry['hostname'] !== $hostname)) {
                    $entry['collision'] = true;
                    throw_unless($store->forever(self::INDEX_KEY . ':' . $nodeId, $entry), RuntimeException::class, 'Unable to record node collision.');

                    return;
                }
            } else {
                $index[] = $nodeId;
            }

            $payload = ['node_id' => $nodeId, 'hostname' => $hostname, 'last_seen_at' => CarbonImmutable::now()->toIso8601String(), 'collision' => false];
            throw_unless($store->forever(self::INDEX_KEY . ':' . $nodeId, $payload), RuntimeException::class, 'Unable to register this node in the shared cache.');
            throw_unless($store->forever(self::INDEX_KEY, $index), RuntimeException::class, 'Unable to register this node in the shared cache.');
            throw_unless($store->forever(self::INDEX_KEY . ':index:created_at', $store->get(self::INDEX_KEY . ':index:created_at') ?? CarbonImmutable::now()->toIso8601String()), RuntimeException::class, 'Unable to create node registry sentinel.');
        });
    }

    /** @return list<string> */
    public function activeNodes(): array
    {
        $store = $this->store();
        $active = [];
        $this->synchronise($store, function () use ($store, &$active): void {
            foreach ($this->index($store) as $nodeId) {
                if ($this->retired($store, $nodeId)) {
                    continue;
                }

                $entry = $store->get(self::INDEX_KEY . ':' . $nodeId);
                $this->validateEntry($entry, $nodeId);
                throw_if(($entry['collision'] ?? false) === true, RuntimeException::class, 'Node identity collision.');
                if (CarbonImmutable::parse($entry['last_seen_at'])->gte(CarbonImmutable::now()->subMinutes($this->ttlMinutes()))) {
                    $active[] = $nodeId;
                }
            }
        });
        sort($active);

        return $active;
    }

    /** @return list<string> */
    public function retiredNodes(): array
    {
        $store = $this->store();
        $retired = [];
        $this->synchronise($store, function () use ($store, &$retired): void {
            foreach ($this->index($store) as $nodeId) {
                if ($this->retired($store, $nodeId)) {
                    $retired[] = $nodeId;
                }
            }
        });

        return $retired;
    }

    /** @return list<string> */
    public function collisions(): array
    {
        $store = $this->store();
        $collisions = [];
        $this->synchronise($store, function () use ($store, &$collisions): void {
            foreach ($this->index($store) as $nodeId) {
                if ($this->retired($store, $nodeId)) {
                    continue;
                }

                $entry = $store->get(self::INDEX_KEY . ':' . $nodeId);
                $this->validateEntry($entry, $nodeId);
                if (($entry['collision'] ?? false) === true) {
                    $collisions[] = $nodeId;
                }
            }
        });

        return $collisions;
    }

    public function retire(string $nodeId): void
    {
        throw_unless(self::validNodeList([$nodeId]), RuntimeException::class, 'Invalid node identity.');
        $store = $this->store();
        $this->synchronise($store, function () use ($store, $nodeId): void {
            $index = $this->index($store);
            throw_unless(in_array($nodeId, $index, true), RuntimeException::class, 'Unknown node identity.');
            throw_unless($store->forever(self::INDEX_KEY . ':retired:' . $nodeId, true), RuntimeException::class, 'Unable to retire node.');
        });
        Log::info('Security node retired.', ['node' => $nodeId]);
    }

    private function retired(Repository $store, string $nodeId): bool
    {
        $marker = $store->get(self::INDEX_KEY . ':retired:' . $nodeId);
        throw_if($marker !== null && $marker !== true, RuntimeException::class, 'Invalid retirement marker.');

        return $marker === true;
    }

    private function validateEntry(mixed $entry, string $nodeId): void
    {
        throw_unless(is_array($entry) && ($entry['node_id'] ?? null) === $nodeId && is_string($entry['hostname'] ?? null) && $entry['hostname'] !== '' && is_string($entry['last_seen_at'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $entry['last_seen_at']) === 1 && (! array_key_exists('collision', $entry) || is_bool($entry['collision'])), RuntimeException::class, 'Invalid node registry entry.');
        try {
            CarbonImmutable::parse($entry['last_seen_at']);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Invalid node registry entry.', 0, $throwable);
        }
    }

    private function store(): Repository
    {
        $name = config('cache.default');
        $driver = is_string($name) ? config('cache.stores.' . $name . '.driver') : null;
        throw_if(! is_string($driver) || in_array($driver, ['array', 'file', 'null'], true), RuntimeException::class, 'Multi-node remediation requires a shared cache store.');

        return Cache::store($name);
    }

    /** @return list<string> */
    private function index(Repository $store, bool $allowCreate = false): array
    {
        $index = $store->get(self::INDEX_KEY);
        $sentinel = $store->get(self::INDEX_KEY . ':index:created_at');
        if ($index === null && $sentinel === null && $allowCreate && ! SecurityRemediationRun::query()->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->exists()) {
            return [];
        }

        throw_unless(is_string($sentinel) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $sentinel) === 1 && self::validNodeList($index, true), RuntimeException::class, 'Unknown node registry.');
        try {
            CarbonImmutable::parse($sentinel);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Unknown node registry.', 0, $throwable);
        }

        return $index;
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
}
