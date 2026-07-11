<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Pages;

use Capell\Admin\Actions\Users\ResolveUserSchemaTypeAction;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Resources\Users\Pages\Concerns\InteractsWithUserFormExtenders;
use Capell\Admin\Filament\Resources\Users\Schemas\UserForm;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Override;

class CreateUser extends CreateRecord
{
    use HasConfigurableFormActionPosition;
    use InteractsWithUserFormExtenders;

    /** @return class-string<UserResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<UserResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::User);

        return $resource;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $resource = static::getResource();
        $roleNames = [];
        $formConfigurator = $resource::getFormConfigurator();

        /** @var class-string<UserForm> $formConfigurator */
        return $formConfigurator::configure($schema, UserSchemaContextData::forCreate(
            roleNames: $roleNames,
            schemaType: ResolveUserSchemaTypeAction::run($roleNames),
            resourceName: 'users',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateUserDataBeforeCreate(parent::mutateFormDataBeforeCreate($data));
    }

    protected function afterCreate(): void
    {
        if (! $this->record instanceof Model) {
            return;
        }

        $this->afterUserCreate($this->record);
    }

    protected function getPositionedFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->submit(null)
                ->action(fn (): mixed => $this->create()),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }
}
