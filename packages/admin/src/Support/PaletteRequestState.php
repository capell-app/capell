<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\PaletteCommandData;
use Capell\Core\Events\PermissionRelationsCleared;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Closure;
use Filament\Panel;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;
use WeakMap;

/** Request-local site resolution and access results, including Livewire middleware replay. */
final class PaletteRequestState
{
    /** @var WeakMap<Authenticatable, array{teamId: ?int}> */
    private WeakMap $teams;

    private int $permissionVersion = 0;

    /** @var WeakMap<Authenticatable, WeakMap<Panel, array<int|string, array{version: int, relations: array<string, mixed>, commands: array<string, PaletteCommandData>}>>> */
    private WeakMap $commands;

    public function __construct()
    {
        $this->teams = new WeakMap;
        $this->commands = new WeakMap;
    }

    public function recordTeamId(Authenticatable $actor, ?int $teamId): void
    {
        $this->teams[$actor] = ['teamId' => $teamId];
    }

    /** @param Closure(): ?int $resolve */
    public function resolvedTeamId(Authenticatable $actor, Closure $resolve): ?int
    {
        if (! isset($this->teams[$actor])) {
            $this->recordTeamId($actor, $resolve());
        }

        return $this->teams[$actor]['teamId'];
    }

    public function forgetPermissionCache(ForgettingKey $event): void
    {
        // ForgettingKey also fires when the backend entry was already absent.
        if ($event->key === resolve(PermissionRegistrar::class)->cacheKey) {
            $this->permissionVersion++;
            $this->teams = new WeakMap;
        }
    }

    public function forgetActorPermissions(PermissionRelationsCleared $event): void
    {
        if ($event->actor instanceof Authenticatable) {
            $this->permissionVersion++;
        }
    }

    /**
     * @param  Closure(): array<string, PaletteCommandData>  $resolve
     * @return array<string, PaletteCommandData>
     */
    public function commands(Authenticatable $actor, Panel $panel, ?int $teamId, Closure $resolve): array
    {
        $panels = $this->commands[$actor] ??= new WeakMap;
        $teams = $panels[$panel] ?? [];
        $key = $teamId ?? 'global';
        $cached = $teams[$key] ?? null;

        if ($cached !== null && $cached['version'] === $this->permissionVersion
            && $cached['relations'] === $this->permissionRelations($actor)) {
            return $cached['commands'];
        }

        $commands = $resolve();
        // Site actors notify even when relations were already unloaded by a team
        // boundary. Other models can only be reused while both loaded relation
        // objects remain identical; an unloaded model has no observable reset hook.
        if ($actor instanceof Model && (in_array(HasSitePermissions::class, class_uses_recursive($actor), true)
            || ($actor->relationLoaded('roles') && $actor->relationLoaded('permissions')))) {
            $teams[$key] = [
                'version' => $this->permissionVersion,
                'relations' => $this->permissionRelations($actor),
                'commands' => $commands,
            ];
            $panels[$panel] = $teams;
        }

        return $commands;
    }

    /** @return array<string, mixed> */
    private function permissionRelations(Authenticatable $actor): array
    {
        return $actor instanceof Model
            ? array_intersect_key($actor->getRelations(), array_flip(['roles', 'permissions']))
            : [];
    }
}
