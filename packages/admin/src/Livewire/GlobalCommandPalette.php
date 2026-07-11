<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire;

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Facades\CapellAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GlobalCommandPalette extends Component
{
    public bool $open = false;

    public string $query = '';

    /**
     * @return list<PaletteCommandData>
     */
    #[Computed]
    public function filteredCommands(): array
    {
        $allCommands = CapellAdmin::getPaletteCommands();

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

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'capell-admin::livewire.global-command-palette';

        return view($view);
    }
}
