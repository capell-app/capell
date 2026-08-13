<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Workspace;

use Capell\Admin\Data\AdminWorkspaceStateData;
use Capell\Admin\Data\ResolvedAdminWorkspaceItemData;
use Capell\Admin\Enums\AdminWorkspaceEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class AdminWorkspaceNavigator
{
    public function __construct(
        private readonly AdminWorkspaceRegistry $registry,
        private readonly AdminWorkspacePreferenceStore $preferences,
    ) {}

    /** @return list<ResolvedAdminWorkspaceItemData> */
    public function items(?Authenticatable $actor, AdminWorkspaceEnum $workspace): array
    {
        return $this->registry->visible($actor, $workspace);
    }

    public function state(?Authenticatable $actor, AdminWorkspaceEnum $workspace): AdminWorkspaceStateData
    {
        $visible = $this->items($actor, $workspace);
        $visibleKeys = array_map(static fn (ResolvedAdminWorkspaceItemData $item): string => $item->key, $visible);
        $state = $actor instanceof Model
            ? $this->preferences->read($actor)
            : ['pinned' => [], 'recent' => []];

        return new AdminWorkspaceStateData(
            workspace: $workspace,
            pinnedKeys: array_values(array_filter($state['pinned'], static fn (string $key): bool => in_array($key, $visibleKeys, true))),
            recentKeys: array_values(array_filter($state['recent'], static fn (string $key): bool => in_array($key, $visibleKeys, true))),
        );
    }

    /** @return list<ResolvedAdminWorkspaceItemData> */
    public function toolItems(?Authenticatable $actor, AdminWorkspaceEnum $workspace): array
    {
        $state = $this->state($actor, $workspace);
        $excludedKeys = array_fill_keys([
            ...$state->pinnedKeys,
            ...$state->recentKeys,
        ], true);

        return array_values(array_filter(
            $this->items($actor, $workspace),
            static fn (ResolvedAdminWorkspaceItemData $item): bool => ! isset($excludedKeys[$item->key]),
        ));
    }

    public function togglePin(?Authenticatable $actor, string $key, AdminWorkspaceEnum $workspace): void
    {
        if (! $actor instanceof Model) {
            return;
        }

        $this->preferences->togglePin($actor, $key, $this->visibleKeys($actor, $workspace));
    }

    public function recordVisit(?Authenticatable $actor, string $key, AdminWorkspaceEnum $workspace): void
    {
        if (! $actor instanceof Model) {
            return;
        }

        $this->preferences->recordVisit($actor, $key, $this->visibleKeys($actor, $workspace));
    }

    /** @return list<string> */
    private function visibleKeys(Authenticatable $actor, AdminWorkspaceEnum $workspace): array
    {
        return array_map(
            static fn (ResolvedAdminWorkspaceItemData $item): string => $item->key,
            $this->registry->visible($actor, $workspace),
        );
    }
}
