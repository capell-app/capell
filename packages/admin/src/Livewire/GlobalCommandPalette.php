<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire;

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Facades\Filament;
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
        if (! $this->isAuthenticated()) {
            return [];
        }

        $allCommands = [...$this->coreCommands(), ...CapellAdmin::getPaletteCommands()];
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

    /**
     * @return list<array{sequence?: string, key?: string, action: string, target: string, label: string}>
     */
    #[Computed]
    public function shortcuts(): array
    {
        if (! $this->isAuthenticated()) {
            return [];
        }

        $commands = $this->coreCommands();

        return array_values(array_filter(
            config('capell-admin.shortcuts', []),
            fn (array $shortcut): bool => $shortcut['action'] !== 'navigate'
                || isset($commands['capell.' . $shortcut['target']]),
        ));
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

        return view($view);
    }

    private function isAuthenticated(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->check();
    }

    /** @return array<string, PaletteCommandData> */
    private function coreCommands(): array
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! Filament::auth()->check()) {
            return [];
        }

        $commands = [];
        foreach ([ResourceEnum::Page, ResourceEnum::Site] as $type) {
            $resource = AdminSurfaceLookup::resourceIfRegistered($type);
            if ($resource === null) {
                continue;
            }

            if (! in_array($resource, $panel->getResources(), true)) {
                continue;
            }

            if (! $resource::canAccess()) {
                continue;
            }

            $key = $type === ResourceEnum::Page ? 'pages' : 'sites';
            $commands['capell.' . $key] = new PaletteCommandData(
                id: 'capell.' . $key,
                label: (string) __('capell-admin::palette.' . $key),
                url: $resource::getUrl(),
                sort: $type === ResourceEnum::Page ? 10 : 20,
            );
        }

        foreach (['settings' => SettingsPage::class, 'extensions' => ExtensionsPage::class] as $key => $page) {
            if (! in_array($page, $panel->getPages(), true)) {
                continue;
            }

            if (! $page::canAccess()) {
                continue;
            }

            $commands['capell.' . $key] = new PaletteCommandData(
                id: 'capell.' . $key,
                label: (string) __('capell-admin::palette.' . $key),
                url: $page::getUrl(),
                sort: $key === 'settings' ? 30 : 40,
            );
        }

        return $commands;
    }
}
