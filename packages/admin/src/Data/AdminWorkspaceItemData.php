<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Capell\Admin\Enums\AdminWorkspaceEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

final class AdminWorkspaceItemData extends Data
{
    /**
     * @param  list<AdminWorkspaceEnum>  $workspaces
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $key,
        public readonly string|Closure $label,
        public readonly string|Closure $url,
        public readonly array $workspaces = [],
        public readonly array $roles = [],
        public readonly ?string $permission = null,
        public readonly string|Closure|null $description = null,
        public readonly null|string|BackedEnum $icon = null,
        public readonly int $sort = 100,
    ) {}

    public function belongsTo(AdminWorkspaceEnum $workspace): bool
    {
        return $workspace === AdminWorkspaceEnum::All
            || in_array($workspace, $this->workspaces, true);
    }

    public function resolveFor(Authenticatable $actor): ResolvedAdminWorkspaceItemData
    {
        return new ResolvedAdminWorkspaceItemData(
            key: $this->key,
            label: $this->resolveValue($this->label, $actor),
            url: $this->resolveValue($this->url, $actor),
            workspaces: $this->workspaces,
            roles: $this->roles,
            permission: $this->permission,
            description: $this->description === null ? null : $this->resolveValue($this->description, $actor),
            icon: $this->icon,
            sort: $this->sort,
        );
    }

    private function resolveValue(string|Closure $value, Authenticatable $actor): string
    {
        return $value instanceof Closure ? (string) $value($actor) : $value;
    }
}
