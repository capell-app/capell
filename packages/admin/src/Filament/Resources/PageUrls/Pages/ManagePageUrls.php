<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\PageUrls\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Resources\Pages\ManageRecords;
use Override;

class ManagePageUrls extends ManageRecords
{
    use HasImportExportHeaderActions;

    /** @return class-string<PageUrlResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<PageUrlResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::PageUrl);

        return $resource;
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->prependImportHeaderAction([
            CreateAction::make(),
        ]);
    }
}
