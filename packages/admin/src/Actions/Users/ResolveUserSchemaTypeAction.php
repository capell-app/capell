<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class ResolveUserSchemaTypeAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $roleNames
     */
    public function handle(array $roleNames): string
    {
        $defaultSchemaType = config('capell-admin.user_resource.default_schema_type', 'default');
        $configuredRoleSchemaTypes = config('capell-admin.user_resource.role_schema_types', []);

        if (! is_array($configuredRoleSchemaTypes)) {
            return is_string($defaultSchemaType) ? $defaultSchemaType : 'default';
        }

        foreach ($configuredRoleSchemaTypes as $roleName => $schemaType) {
            if (! is_string($roleName) || ! is_string($schemaType)) {
                return is_string($defaultSchemaType) ? $defaultSchemaType : 'default';
            }

            if (in_array($roleName, $roleNames, true)) {
                return $schemaType;
            }
        }

        return is_string($defaultSchemaType) ? $defaultSchemaType : 'default';
    }
}
