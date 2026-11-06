<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

final class PaletteAccess
{
    /** @return array<string, PaletteCommandData> */
    public function coreCommands(): array
    {
        $panel = Filament::getCurrentPanel();
        $user = $panel !== null ? Filament::auth()->user() : null;

        if ($panel === null || $user === null) {
            return [];
        }

        $state = resolve(PaletteRequestState::class);
        $teamId = $state->resolvedTeamId($user, fn (): ?int => resolve(SetSitePermissionScope::class)->permissionTeamId(request()));

        return $state->commands($user, $panel, $teamId, function () use ($teamId, $user): array {
            $activeTeamId = resolve(PermissionRegistrar::class)->getPermissionsTeamId();
            if (($activeTeamId === null ? null : (int) $activeTeamId) === $teamId) {
                // A memo miss can follow either relation being cleared. Reload
                // both so the other relation cannot retain a revoked grant.
                if ($user instanceof Model) {
                    $user->unsetRelation('roles')->unsetRelation('permissions');
                }

                return $this->resolveCoreCommands();
            }

            return PermissionTeamContext::run(
                $teamId,
                fn (): array => $this->resolveCoreCommands(),
                $user instanceof Model ? $user : null,
            );
        });
    }

    /** @return list<array{sequence?: string, key?: string, action: string, target: string, label: string}> */
    public function shortcuts(): array
    {
        if (Filament::getCurrentPanel() === null || ! Filament::auth()->check()) {
            return [];
        }

        $commands = $this->coreCommands();

        return array_values(array_filter(
            config('capell-admin.shortcuts', []),
            fn (array $shortcut): bool => $shortcut['action'] !== 'navigate'
                || isset($commands['capell.' . $shortcut['target']]),
        ));
    }

    /** @return array<string, PaletteCommandData> */
    private function resolveCoreCommands(): array
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
