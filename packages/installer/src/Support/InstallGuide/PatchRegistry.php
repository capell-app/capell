<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Support\Collection;

/** @extends AbstractKeyedRegistry<Patch> */
class PatchRegistry extends AbstractKeyedRegistry
{
    public function register(Patch $patch): self
    {
        $this->setItem($patch->id(), $patch);

        return $this;
    }

    /**
     * @return Collection<string, Patch>
     */
    public function all(): Collection
    {
        return collect($this->allItems())
            ->sortBy(static fn (Patch $patch): string => $patch->group());
    }

    public function get(string $patchId): ?Patch
    {
        return $this->getItem($patchId);
    }
}
