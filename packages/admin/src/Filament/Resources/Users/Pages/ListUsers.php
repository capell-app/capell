<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListUsers extends ListRecords
{
    /** @return class-string<UserResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<UserResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::User);

        return $resource;
    }

    #[Override]
    protected function getActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
