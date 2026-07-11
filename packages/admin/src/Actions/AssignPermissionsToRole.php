<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\ResourceEnum;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(array<int, ResourceEnum|class-string> $resources = [], array<int, PageEnum|class-string> $pages = [], array<int, FilamentWidgetEnum|class-string> $widgets = [])
 */
class AssignPermissionsToRole
{
    use AsObject;

    /**
     * @param  array<int, ResourceEnum|class-string>  $resources
     * @param  array<int, PageEnum|class-string>  $pages
     * @param  array<int, FilamentWidgetEnum|class-string>  $widgets
     */
    public function handle(array $resources = [], array $pages = [], array $widgets = []): void
    {
        if ($resources !== []) {
            $this->generateResourcePermissions($resources);
        }

        if ($pages !== []) {
            $this->generateForPageOrWidget($pages);
        }

        if ($widgets !== []) {
            $this->generateForPageOrWidget($widgets);
        }
    }

    /**
     * @param  array<int, FilamentWidgetEnum|PageEnum|class-string>  $cases
     */
    private function generateForPageOrWidget(array $cases): void
    {
        foreach ($cases as $case) {
            $class = is_string($case) ? $case : $case->value;

            Utils::generateForPageOrWidget('View:' . class_basename($class));
        }
    }

    /**
     * @param  array<int, ResourceEnum|class-string>  $resources
     */
    private function generateResourcePermissions(array $resources): void
    {
        foreach ($resources as $resource) {
            $resourceClass = is_string($resource) ? $resource : $resource->value;

            Utils::generateForResource($resourceClass);
        }
    }
}
