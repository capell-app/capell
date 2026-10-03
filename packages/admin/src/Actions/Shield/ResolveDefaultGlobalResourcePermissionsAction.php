<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Shield;

use Capell\Admin\Enums\ResourceEnum;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Retain default administrator access when explicit global-resource policies are installed.
 *
 * @method static list<string> run(?ResourceEnum $resource = null)
 */
final class ResolveDefaultGlobalResourcePermissionsAction
{
    use AsFake;
    use AsObject;

    /** @return list<string> */
    public function handle(?ResourceEnum $resource = null): array
    {
        $permissions = [];
        foreach ($resource instanceof ResourceEnum ? [$resource] : [ResourceEnum::Theme, ResourceEnum::Blueprint, ResourceEnum::Language] as $globalResource) {
            foreach (['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'restore', 'restore_any', 'force_delete', 'force_delete_any', 'replicate', 'reorder'] as $affix) {
                $permissions[] = $globalResource->permission($affix);
            }
        }

        return $permissions;
    }
}
