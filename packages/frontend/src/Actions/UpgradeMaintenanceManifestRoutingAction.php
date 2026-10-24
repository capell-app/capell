<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** Re-render legacy artefacts from current routing, never infer a missing port. */
final class UpgradeMaintenanceManifestRoutingAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MaintenanceManifestStore $store) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $manifest = $this->store->read();
        if (! $this->store->needsRoutingUpgrade($manifest)) {
            return $manifest;
        }

        foreach (array_keys($manifest['sites'] ?? []) as $siteId) {
            $site = Site::query()->findOrFail($siteId);
            GenerateMaintenancePageCacheAction::run($site);
        }

        $manifest = $this->store->read();
        $manifest['version'] = 2;
        throw_if($this->store->needsRoutingUpgrade($manifest), RuntimeException::class, 'Maintenance routing conversion is incomplete.');
        $this->store->write($manifest);

        // Re-read: a worker from the previous release may have replaced the file.
        $manifest = $this->store->read();
        throw_if($this->store->needsRoutingUpgrade($manifest), RuntimeException::class, 'Maintenance routing was replaced by a legacy writer.');

        return $manifest;
    }
}
