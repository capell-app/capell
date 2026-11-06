<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Data\PaletteRenderData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

final class PaletteAccess
{
    public function forRender(): PaletteRenderData
    {
        return $this->inSiteScope(function (): PaletteRenderData {
            $commands = [...$this->resolveCoreCommands(), ...CapellAdmin::getPaletteCommands()];

            return new PaletteRenderData($commands, $this->shortcuts($commands));
        }, new PaletteRenderData([], []));
    }

    /** @return array<string, PaletteCommandData> */
    public function coreCommands(): array
    {
        return $this->inSiteScope(fn (): array => $this->resolveCoreCommands(), []);
    }

    /**
     * @param  array<string, PaletteCommandData>  $commands
     * @return list<array{sequence?: string, key?: string, action: string, target: string, label: string, url?: string}>
     */
    public function shortcuts(array $commands): array
    {
        $shortcuts = [];
        foreach (config('capell-admin.shortcuts', []) as $shortcut) {
            if ($shortcut['action'] === 'navigate') {
                $path = '/' . trim((Filament::getCurrentPanel()?->getPath() ?? '') . '/' . $shortcut['target'], '/');
                $command = array_find($commands, fn (PaletteCommandData $command): bool => $command->url !== null
                    && ($command->id === $shortcut['target']
                        || $command->id === 'capell.' . $shortcut['target']
                        || parse_url($command->url, PHP_URL_PATH) === $path));
                if ($command === null) {
                    continue;
                }

                $shortcut['url'] = $command->url;
            }

            $shortcuts[] = $shortcut;
        }

        return $shortcuts;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $resolve
     * @param  TResult  $guest
     * @return TResult
     */
    private function inSiteScope(Closure $resolve, mixed $guest): mixed
    {
        $panel = Filament::getCurrentPanel();
        $user = $panel !== null ? Filament::auth()->user() : null;
        if ($panel === null || $user === null) {
            return $guest;
        }

        // Route context is authoritative even after the Livewire middleware has
        // restored the registrar's team. It is independent of permission grants.
        $teamId = resolve(PaletteRequestState::class)->resolvedTeamId(
            $user,
            fn (): ?int => resolve(SetSitePermissionScope::class)->permissionTeamId(request()),
        );

        return PermissionTeamContext::run($teamId, function () use ($resolve): mixed {
            // Direct role-permission pivot writes do not invalidate Spatie's
            // cached permission-to-role links. Refresh them once for this render.
            resolve(PermissionRegistrar::class)->getPermissions()->load('roles');

            return $resolve();
        }, $user instanceof Model ? $user : null);
    }

    /** @return array<string, PaletteCommandData> */
    private function resolveCoreCommands(): array
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! Filament::auth()->check()) {
            return [];
        }

        $commands = [];
        foreach (['pages' => ResourceEnum::Page, 'sites' => ResourceEnum::Site, 'themes' => ResourceEnum::Theme, 'publishing-studio' => 'Workspace'] as $key => $type) {
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

            $commands['capell.' . $key] = new PaletteCommandData(
                id: 'capell.' . $key,
                label: (string) __('capell-admin::palette.' . $key),
                url: $resource::getUrl(),
                sort: match ($key) {
                    'pages' => 10,
                    'sites' => 20,
                    'themes' => 50,
                    default => 60,
                },
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
