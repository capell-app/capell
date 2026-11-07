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

    protected ?PaletteRenderData $initialAccess = null;

    public function mount(?PaletteRenderData $access = null): void
    {
        $this->initialAccess = $access;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function runCommand(string $id): bool
    {
        $command = resolve(PaletteAccess::class)->forRender()->commands[$id] ?? null;
        if ($command === null) {
            return false;
        }

        $this->close();
        if ($command->url !== null) {
            $this->redirect($command->url, navigate: true);
        }

        return true;
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

        $commands = $access->commands;
        uasort($commands, static fn (PaletteCommandData $first, PaletteCommandData $second): int => $first->sort <=> $second->sort);

        return view($view, [
            'commands' => array_values($commands),
            'shortcuts' => $access->shortcuts,
        ]);
    }

    private function isAuthenticated(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->check();
    }
}
