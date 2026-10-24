<?php

declare(strict_types=1);

namespace Capell\Core\Support\Themes;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/** @extends AbstractKeyedRegistry<array<string, string>> */
final class LayoutViewRegistry extends AbstractKeyedRegistry
{
    public function registerMaster(string $view, string $label): void
    {
        $this->setItem('masters', [...$this->masterOptions(), $view => $label]);
    }

    public function registerLayout(string $view, string $label): void
    {
        $this->setItem('layouts', [...$this->layoutOptions(), $view => $label]);
    }

    /** @return array<string, string> */
    public function masterOptions(): array
    {
        return $this->getItem('masters') ?? [];
    }

    /** @return array<string, string> */
    public function layoutOptions(): array
    {
        return $this->getItem('layouts') ?? [];
    }
}
