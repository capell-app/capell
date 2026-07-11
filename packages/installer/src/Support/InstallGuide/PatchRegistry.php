<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide;

use Illuminate\Support\Collection;

class PatchRegistry
{
    /**
     * @var Collection<string, Patch>
     */
    private readonly Collection $patches;

    public function __construct()
    {
        $this->patches = collect();
    }

    public function register(Patch $patch): self
    {
        $this->patches->put($patch->id(), $patch);

        return $this;
    }

    /**
     * @return Collection<string, Patch>
     */
    public function all(): Collection
    {
        return $this->patches->sortBy(static fn (Patch $patch): string => $patch->group());
    }

    public function get(string $patchId): ?Patch
    {
        return $this->patches->get($patchId);
    }
}
