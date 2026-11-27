<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Application;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Data\ApplicationAreaData;

/**
 * The application areas a host or package declares, keyed by area key.
 *
 * @experimental
 *
 * @extends AbstractKeyedRegistry<ApplicationAreaData>
 */
final class ApplicationAreaRegistry extends AbstractKeyedRegistry
{
    public function register(ApplicationAreaData $area): self
    {
        $this->setItem($area->key, $area);

        return $this;
    }

    public function get(string $key): ?ApplicationAreaData
    {
        $area = $this->hasItem($key) ? $this->getItem($key) : null;

        return $area instanceof ApplicationAreaData ? $area : null;
    }

    /** @return array<string, ApplicationAreaData> */
    public function all(): array
    {
        return $this->allItems();
    }
}
