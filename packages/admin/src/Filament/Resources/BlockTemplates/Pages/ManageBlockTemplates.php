<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\BlockTemplates\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Resources\Pages\ManageRecords;
use Override;

final class ManageBlockTemplates extends ManageRecords
{
    /** @return class-string<BlockTemplateResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<BlockTemplateResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::BlockTemplate);

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
