<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Workspace;

use Capell\Admin\Data\AdminWorkspaceItemData;
use Capell\Admin\Data\ResolvedAdminWorkspaceItemData;
use Capell\Admin\Enums\AdminWorkspaceEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

final class AdminWorkspaceRegistry
{
    /** @var array<string, AdminWorkspaceItemData> */
    private array $items = [];

    private int $generation = 0;

    public function register(AdminWorkspaceItemData $item): void
    {
        if (! $this->isSafeKey($item->key)
            || (is_string($item->url) && ! $this->isSafeDestination($item->url))
            || $item->workspaces === []) {
            return;
        }

        $this->items[$item->key] = $item;
        $this->generation++;
    }

    /** @return array<string, AdminWorkspaceItemData> */
    public function definitions(): array
    {
        return $this->items;
    }

    /**
     * Resolve only items the actor is allowed to discover. Search, pins, and
     * recents must all consume this same filtered set. This is discovery
     * filtering only; destination Page/Resource policies remain authoritative
     * when the URL is opened directly.
     *
     * @return list<ResolvedAdminWorkspaceItemData>
     */
    public function visible(?Authenticatable $actor, AdminWorkspaceEnum $workspace = AdminWorkspaceEnum::All): array
    {
        if (! $actor instanceof Authenticatable) {
            return [];
        }

        $items = [];

        foreach ($this->items as $item) {
            if (! $item->belongsTo($workspace)
                || ! $this->matchesRole($item, $actor)
                || ! $this->matchesPermission($item, $actor)) {
                continue;
            }

            try {
                $resolved = $item->resolveFor($actor);
            } catch (Throwable) {
                continue;
            }

            if (! $this->isSafeDestination($resolved->url)) {
                continue;
            }

            $items[$resolved->key] = $resolved;
        }

        uasort($items, static fn (ResolvedAdminWorkspaceItemData $first, ResolvedAdminWorkspaceItemData $second): int => $first->sort <=> $second->sort ?: $first->key <=> $second->key);

        return array_values($items);
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function clear(): void
    {
        $this->items = [];
        $this->generation++;
    }

    private function isSafeKey(string $key): bool
    {
        return preg_match('/\\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\\z/', $key) === 1;
    }

    private function isSafeDestination(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\')
            && preg_match('/[\\x00-\\x1F\\x7F]/', $url) !== 1;
    }

    private function matchesRole(AdminWorkspaceItemData $item, Authenticatable $actor): bool
    {
        try {
            if ($item->roles === [] || (method_exists($actor, 'isGlobalAdmin') && $actor->isGlobalAdmin())) {
                return true;
            }

            if (! method_exists($actor, 'hasRole')) {
                return false;
            }

            foreach ($item->roles as $role) {
                if ($actor->hasRole($role)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function matchesPermission(AdminWorkspaceItemData $item, Authenticatable $actor): bool
    {
        if ($item->permission === null || $item->permission === '') {
            return true;
        }

        try {
            if (method_exists($actor, 'checkPermissionTo')) {
                return (bool) $actor->checkPermissionTo($item->permission);
            }

            if (method_exists($actor, 'can')) {
                return (bool) $actor->can($item->permission);
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
