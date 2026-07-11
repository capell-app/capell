<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use BackedEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?string run((ResourceEnum|BackedEnum) $pageGroup, ?Blueprint $type = null)
 */
class GetResourceFromBlueprintAction
{
    use AsObject;

    public function handle(ResourceEnum|BackedEnum|string $pageGroup, ?Blueprint $type = null): string
    {
        throw_unless(CapellCore::hasPackage(AdminServiceProvider::$packageName), InvalidArgumentException::class, 'Admin package is not installed.');

        $name = $type->admin['resource'] ?? 'default';

        $group = is_string($pageGroup) ? $pageGroup : $pageGroup->name;
        throw_unless(CapellAdmin::hasResource($group, $name), InvalidArgumentException::class, 'Resource not found for type: ' . $group . ', name: ' . $name);

        $resource = CapellAdmin::getResource($group, $name);

        throw_if($resource === null, InvalidArgumentException::class, 'Resource not found for type: ' . $group . ', name: ' . $name);

        return $resource;
    }
}
