<?php

declare(strict_types=1);

namespace Capell\Core\Support\Hosting;

use RuntimeException;

final class MultiNodeTopologyGuard
{
    /** @var list<string> */
    private const array NODE_LOCAL_CACHE_DRIVERS = ['array', 'file', 'null'];

    public function assertNodeLocalOperationIsAllowed(string $operation): void
    {
        if (! $this->isMultiNode()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s cannot run while CAPELL_MULTI_NODE=true because it writes output on only one application node. Publish the output through a shared deployment or storage workflow, or set CAPELL_MULTI_NODE=false for a single-node installation.',
            $operation,
        ));
    }

    public function assertFilesystemDiskIsShared(string $disk, string $operation): void
    {
        if (! $this->isMultiNode()) {
            return;
        }

        $driver = config(sprintf('filesystems.disks.%s.driver', $disk));

        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException(sprintf(
                '%s cannot run while CAPELL_MULTI_NODE=true because the [%s] filesystem disk has no resolvable driver. Configure shared storage for that disk, or set CAPELL_MULTI_NODE=false for a single-node installation.',
                $operation,
                $disk,
            ));
        }

        if ($driver !== 'local') {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s cannot run while CAPELL_MULTI_NODE=true because the [%s] filesystem disk uses the node-local [%s] driver. Configure shared storage for that disk, or set CAPELL_MULTI_NODE=false for a single-node installation.',
            $operation,
            $disk,
            $driver,
        ));
    }

    public function assertCacheStoreIsShared(string $operation): void
    {
        if (! $this->isMultiNode()) {
            return;
        }

        $store = (string) config('cache.default');
        $driver = config(sprintf('cache.stores.%s.driver', $store));

        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException(sprintf(
                '%s cannot run while CAPELL_MULTI_NODE=true because cache store [%s] has no resolvable driver. Configure a shared Redis or Memcached cache store, or set CAPELL_MULTI_NODE=false for a single-node installation.',
                $operation,
                $store,
            ));
        }

        if (! in_array($driver, self::NODE_LOCAL_CACHE_DRIVERS, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s cannot run while CAPELL_MULTI_NODE=true because cache store [%s] uses the node-local [%s] driver. Configure a shared Redis or Memcached cache store, or set CAPELL_MULTI_NODE=false for a single-node installation.',
            $operation,
            $store,
            $driver,
        ));
    }

    private function isMultiNode(): bool
    {
        return config('capell.multi_node', false) === true;
    }
}
