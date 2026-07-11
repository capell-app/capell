<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Activities\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Resources\Pages\ListRecords;
use Override;

final class ListActivities extends ListRecords
{
    /** @return class-string<ActivityResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<ActivityResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Activity);

        return $resource;
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-admin::activity.list_subheading');
    }
}
