<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Override;

class CreateLayout extends CreateRecord
{
    use HasConfigurableFormActionPosition;

    /** @return class-string<LayoutResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<LayoutResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Layout);

        return $resource;
    }

    /**
     * @return array<Action>
     */
    protected function getPositionedFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->submit(null)
                ->action(function (): void {
                    $this->create();
                }),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }
}
