<?php

declare(strict_types=1);

namespace Capell\Core\Data\Permissions;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * One ability a package declares, with the roles that receive it when the
 * package is installed. Keys are the exact strings policies and gates check.
 */
final class PermissionData extends Data
{
    /**
     * @param  list<string>  $defaultRoles  role names granted the permission on install
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $group = null,
        public array $defaultRoles = [],
    ) {
        if (trim($key) === '' || preg_match('/\s/', $key) === 1) {
            throw new InvalidArgumentException(sprintf('Permission key [%s] must be a non-empty string without whitespace.', $key));
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(sprintf('Permission [%s] needs a label.', $key));
        }

        foreach ($defaultRoles as $role) {
            if (! is_string($role) || trim($role) === '') {
                throw new InvalidArgumentException(sprintf('Permission [%s] has an empty default role.', $key));
            }
        }
    }
}
