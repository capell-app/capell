<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Blueprints;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Blueprint run(Blueprint $blueprint, array<string, mixed> $data)
 */
final class UpdateBlueprintAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(Blueprint $blueprint, array $data): Blueprint
    {
        $roleRestrictions = $data['admin']['role_restrictions'] ?? null;
        unset($data['admin']['role_restrictions']);

        $blueprint->update($data);

        if (auth()->user()?->can('manageRestrictions', Page::class) === true && is_array($roleRestrictions)) {
            $blueprint->syncRoleRestrictions(
                array_values(array_map(intval(...), $roleRestrictions)),
            );
        }

        return $blueprint;
    }
}
