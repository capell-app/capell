<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use Capell\Admin\Data\PaletteCommandData;

trait HasPaletteCommands
{
    /** @var array<string, PaletteCommandData> */
    protected array $paletteCommands = [];

    /**
     * Register a command palette entry.
     * Add-on packages call this during their boot phase.
     */
    public function registerCommand(PaletteCommandData $command): static
    {
        $this->paletteCommands[$command->id] = $command;

        return $this;
    }

    /**
     * Return all registered palette commands, sorted by their weight.
     *
     * @return array<string, PaletteCommandData>
     */
    public function getPaletteCommands(): array
    {
        $commands = $this->paletteCommands;

        uasort($commands, fn (PaletteCommandData $first, PaletteCommandData $second): int => $first->sort <=> $second->sort);

        return $commands;
    }
}
