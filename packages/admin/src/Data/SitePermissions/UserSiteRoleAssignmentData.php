<?php

declare(strict_types=1);

namespace Capell\Admin\Data\SitePermissions;

use Spatie\LaravelData\Data;

final class UserSiteRoleAssignmentData extends Data
{
    /**
     * @param  array<int>  $roleIds
     */
    public function __construct(
        public int $userId,
        public array $roleIds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $roleIds = is_array($data['role_ids'] ?? null)
            ? $data['role_ids']
            : [];

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            roleIds: collect($roleIds)
                ->map(fn (mixed $roleId): int => (int) $roleId)
                ->filter(fn (int $roleId): bool => $roleId > 0)
                ->unique()
                ->values()
                ->all(),
        );
    }
}
