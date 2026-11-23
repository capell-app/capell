<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Shield;

use Capell\Admin\Support\Impersonation\ImpersonationCeiling;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The most a role editor may put on a role: only permissions the editor holds
 * in the role's own team. Without it, anyone allowed to edit roles could grant
 * themselves, or an account they control, authority they were never given.
 */
final readonly class RolePermissionCeiling
{
    public function __construct(private ImpersonationCeiling $held) {}

    /**
     * @param  iterable<mixed>  $requested
     * @return list<string> The requested permission names the actor does not hold.
     */
    public function exceeding(?Authenticatable $actor, iterable $requested, int|string|null $teamId): array
    {
        if (! $actor instanceof Authenticatable) {
            return array_values(array_filter(
                collect($requested)->filter(is_string(...))->unique()->all(),
            ));
        }

        $held = $this->held->heldPermissionNames($actor, $teamId === null ? null : (int) $teamId);

        if ($held === null) {
            return [];
        }

        return array_values(collect($requested)
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '' && ! isset($held[$name]))
            ->unique()
            ->all());
    }
}
