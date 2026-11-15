<?php

declare(strict_types=1);

namespace Capell\Core\Data\Permissions;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * The permissions one package declares. Keys are unique within the set.
 */
final class PermissionSetData extends Data
{
    /**
     * @param  list<PermissionData>  $permissions
     */
    public function __construct(
        public array $permissions = [],
    ) {
        $seen = [];

        foreach ($permissions as $permission) {
            throw_unless($permission instanceof PermissionData, InvalidArgumentException::class, 'A permission set holds PermissionData only.');

            if (isset($seen[$permission->key])) {
                throw new InvalidArgumentException(sprintf('Permission [%s] is declared twice.', $permission->key));
            }

            $seen[$permission->key] = true;
        }
    }

    public static function of(PermissionData ...$permissions): self
    {
        return new self(array_values($permissions));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (PermissionData $permission): string => $permission->key, $this->permissions);
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function merge(self $other): self
    {
        return new self([...$this->permissions, ...$other->permissions]);
    }
}
