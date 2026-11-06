<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire;

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Data\PaletteRenderData;
use Capell\Admin\Support\PaletteAccess;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class GlobalCommandPalette extends Component
{
    public bool $open = false;

    public string $query = '';

    protected ?PaletteRenderData $initialAccess = null;

    public function mount(?PaletteRenderData $access = null): void
    {
        $this->initialAccess = $access;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;

        if (! $this->open) {
            $this->query = '';
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    public function render(): View|string
    {
        if (! $this->isAuthenticated()) {
            return '<div></div>';
        }

        /** @var view-string $view */
        $view = 'capell-admin::livewire.global-command-palette';

        $access = $this->initialAccess ?? resolve(PaletteAccess::class)->forRender();
        $this->initialAccess = null;

        return view($view, [
            'commands' => $this->filteredCommands($access->commands),
            'shortcuts' => $access->shortcuts,
        ]);
    }

    /**
     * @param  array<string, PaletteCommandData>|null  $commands
     * @return list<PaletteCommandData>
     */
    protected function filteredCommands(?array $commands = null): array
    {
        if (! $this->isAuthenticated()) {
            return [];
        }

        $allCommands = $commands ?? resolve(PaletteAccess::class)->forRender()->commands;
        uasort($allCommands, fn (PaletteCommandData $first, PaletteCommandData $second): int => $first->sort <=> $second->sort);

        if ($this->query === '') {
            return array_values($allCommands);
        }

        $normalizedQuery = mb_strtolower($this->query);

        return array_values(
            array_filter(
                $allCommands,
                fn (PaletteCommandData $command): bool => str_contains(mb_strtolower($command->label), $normalizedQuery)
                    || ($command->description !== null && str_contains(mb_strtolower($command->description), $normalizedQuery)),
            ),
        );
    }

    private function isAuthenticated(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->check();
    }
}
