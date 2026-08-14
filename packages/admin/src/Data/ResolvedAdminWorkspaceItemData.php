<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Capell\Admin\Enums\AdminWorkspaceEnum;
use Spatie\LaravelData\Data;

final class ResolvedAdminWorkspaceItemData extends Data
{
    /**
     * @param  list<AdminWorkspaceEnum>  $workspaces
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $url,
        public readonly array $workspaces = [],
        public readonly array $roles = [],
        public readonly ?string $permission = null,
        public readonly ?string $description = null,
        public readonly null|string|BackedEnum $icon = null,
        public readonly int $sort = 100,
    ) {}
}
