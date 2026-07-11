<?php

declare(strict_types=1);

namespace Capell\Admin\Data\SitePermissions;

use Spatie\LaravelData\Data;

final class SyncSitePermissionsData extends Data
{
    /**
     * @param  array<int, UserSiteRoleAssignmentData>  $assignments
     */
    public function __construct(
        public array $assignments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $assignments = is_array($data['assignments'] ?? null)
            ? $data['assignments']
            : [];

        return new self(
            assignments: collect($assignments)
                ->filter(fn (mixed $assignment): bool => is_array($assignment))
                ->map(fn (array $assignment): UserSiteRoleAssignmentData => UserSiteRoleAssignmentData::fromArray($assignment))
                ->filter(fn (UserSiteRoleAssignmentData $assignment): bool => $assignment->userId > 0)
                ->values()
                ->all(),
        );
    }
}
