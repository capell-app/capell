<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Pages;

use Capell\Admin\Actions\Users\ResolveUserSchemaTypeAction;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Resources\Users\Pages\Concerns\InteractsWithUserResourceBridges;
use Capell\Admin\Filament\Resources\Users\Schemas\UserForm;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

class EditUser extends EditRecord
{
    use HasConfigurableFormActionPosition;
    use InteractsWithUserResourceBridges;

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
        $record = $this->recordModel();
        $roleNames = $this->resolveUserRoleNames($record);
        $formConfigurator = $resource::getFormConfigurator();

        /** @var class-string<UserForm> $formConfigurator */
        return $formConfigurator::configure($schema, UserSchemaContextData::forEdit(
            record: $record,
            roleNames: $roleNames,
            schemaType: ResolveUserSchemaTypeAction::run($roleNames),
            resourceName: 'users',
        ));
    }

    #[Override]
    protected function getAllRelationManagers(): array
    {
        $record = $this->recordModel();
        $roleNames = $this->resolveUserRoleNames($record);
        $context = UserSchemaContextData::forEdit(
            record: $record,
            roleNames: $roleNames,
            schemaType: ResolveUserSchemaTypeAction::run($roleNames),
            resourceName: 'users',
        );

        return resolve(AdminSchemaExtensionPipeline::class)
            ->userRelationManagers($record, parent::getAllRelationManagers(), $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->mutateUserDataBeforeSave($this->recordModel(), parent::mutateFormDataBeforeSave($data));
    }

    protected function afterSave(): void
    {
        $this->afterUserSave($this->recordModel());
    }

    protected function getPositionedFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->submit(null)
                ->action(function (): void {
                    $this->save();
                }),
            $this->getCancelFormAction(),
        ];
    }

    private function recordModel(): Model
    {
        if ($this->record instanceof Model) {
            return $this->record;
        }

        throw new LogicException('EditUser record has not been resolved.');
    }
}
