<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\AdminResourceResolver;
use Capell\Core\Models\Blueprint;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(?Blueprint $type = null)
 */
class GetResourceFromBlueprintAction
{
    use AsObject;

    public function handle(?Blueprint $type = null): string
    {
        throw_unless(app()->bound(AdminResourceResolver::class), InvalidArgumentException::class, 'Admin package is not installed.');

        $name = $type->admin['resource'] ?? 'default';

        $resolver = resolve(AdminResourceResolver::class);
        throw_unless($resolver->hasPageResource($name), InvalidArgumentException::class, 'Page resource not found for name: ' . $name);

        $resource = $resolver->getPageResource($name);

        throw_if($resource === null, InvalidArgumentException::class, 'Page resource not found for name: ' . $name);

        return $resource;
    }
}
