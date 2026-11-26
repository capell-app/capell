<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Application;

use Capell\Frontend\Data\ApplicationAreaData;

/**
 * The application areas a host or package declares, keyed by area key.
 *
 * @experimental
 */
final class ApplicationAreaRegistry
{
    /** @var array<string, ApplicationAreaData> */
    private array $areas = [];

    public function register(ApplicationAreaData $area): self
    {
        $this->areas[$area->key] = $area;

        return $this;
    }

    public function get(string $key): ?ApplicationAreaData
    {
        return $this->areas[$key] ?? null;
    }

    /** @return array<string, ApplicationAreaData> */
    public function all(): array
    {
        return $this->areas;
    }
}
