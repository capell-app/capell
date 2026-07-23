<?php

declare(strict_types=1);

namespace Capell\Core\Support\Hosting;

use RuntimeException;

final class MultiNodeTopologyGuard
{
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

    private function isMultiNode(): bool
    {
        return config('capell.multi_node', false) === true;
    }
}
