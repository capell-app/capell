<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

/**
 * Flags cache stores that cannot be shared between application nodes.
 *
 * Capell builds correctness guarantees on Cache::lock — most importantly the
 * upgrade lock, which is what stops two nodes running migrations at once. Those
 * locks are only as global as the store behind them, and the file and array
 * drivers are private to a single node or even a single process.
 *
 * Single-node hosting is perfectly fine on the file driver, so this is a warning
 * that explains the constraint rather than a failure.
 */
final class SharedCacheStoreCheck extends AbstractDoctorCheck
{
    /** @var list<string> */
    private const array NODE_LOCAL_DRIVERS = ['file', 'array', 'null'];

    protected function id(): string
    {
        return 'core.cache.shared-store';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $label = 'Cache store can be shared between nodes';
        $storeName = (string) config('cache.default');
        $driver = (string) config(sprintf('cache.stores.%s.driver', $storeName));
        $multiNode = config('capell.multi_node', false) === true;
        $evidence = ['store' => $storeName, 'driver' => $driver, 'multi_node' => $multiNode];

        if ($driver === '') {
            return new DoctorCheckResultData(
                $label,
                true,
                sprintf('Cache store [%s] has no resolvable driver; skipping the shared-store check.', $storeName),
                severity: $this->severity(),
                evidence: $evidence,
            );
        }

        if (! in_array($driver, self::NODE_LOCAL_DRIVERS, true)) {
            return new DoctorCheckResultData(
                $label,
                true,
                sprintf('Cache store [%s] uses the [%s] driver, which can be shared between nodes.', $storeName, $driver),
                severity: $this->severity(),
                evidence: $evidence,
            );
        }

        if (! $multiNode) {
            return new DoctorCheckResultData(
                $label,
                true,
                sprintf('Cache store [%s] uses the node-local [%s] driver, which is safe because this installation is configured for a single application node.', $storeName, $driver),
                severity: $this->severity(),
                evidence: $evidence,
            );
        }

        return new DoctorCheckResultData(
            $label,
            false,
            sprintf(
                'Cache store [%s] uses the [%s] driver, which is local to one node. This is safe on a single server, but behind a load balancer Capell\'s locks stop being global — two nodes could run an upgrade and its migrations at the same time.',
                $storeName,
                $driver,
            ),
            'Point CACHE_STORE at a shared Redis or Memcached instance, or set CAPELL_MULTI_NODE=false when this installation runs on one application node.',
            severity: $this->severity(),
            evidence: $evidence,
        );
    }
}
