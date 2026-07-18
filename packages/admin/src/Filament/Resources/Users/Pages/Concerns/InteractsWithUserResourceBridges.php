<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Pages\Concerns;

use Capell\Admin\Actions\Users\ResolveUserSchemaTypeAction;
use Capell\Admin\Actions\Users\SetUserPreferredAdminLanguageAction;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Support\Bridges\UserResourceBridgeResolver;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

trait InteractsWithUserResourceBridges
{
    private mixed $preferredAdminLanguageId = null;

    private bool $shouldPersistPreferredAdminLanguage = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateUserDataBeforeCreate(array $data): array
    {
        $this->extractPreferredAdminLanguageId($data);

        $data = resolve(UserResourceBridgeResolver::class)
            ->mutateDataBeforeCreate($data, $this->userSchemaContext());

        return $this->hashPasswordWhenPresent($data);
    }

    protected function afterUserCreate(Model $record): void
    {
        $this->persistPreferredAdminLanguage($record);

        resolve(UserResourceBridgeResolver::class)
            ->afterCreate($record, $this->userSchemaContext());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateUserDataBeforeSave(Model $record, array $data): array
    {
        $this->extractPreferredAdminLanguageId($data);

        $data = resolve(UserResourceBridgeResolver::class)
            ->mutateDataBeforeSave($record, $data, $this->userSchemaContext($record));

        return $this->hashPasswordWhenPresent($data);
    }

    protected function afterUserSave(Model $record): void
    {
        $this->persistPreferredAdminLanguage($record);

        resolve(UserResourceBridgeResolver::class)
            ->afterSave($record, $this->userSchemaContext($record));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function hashPasswordWhenPresent(array $data): array
    {
        if (! array_key_exists('password', $data) || blank($data['password'])) {
            unset($data['password']);

            return $data;
        }

        $data['password'] = Hash::make((string) $data['password']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPreferredAdminLanguageId(array &$data): void
    {
        if (! array_key_exists('preferred_admin_language_id', $data)) {
            return;
        }

        $this->preferredAdminLanguageId = $data['preferred_admin_language_id'];
        $this->shouldPersistPreferredAdminLanguage = true;

        unset($data['preferred_admin_language_id']);
    }

    private function persistPreferredAdminLanguage(Model $record): void
    {
        if (! $this->shouldPersistPreferredAdminLanguage) {
            return;
        }

        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable('users') || ! $schema->hasColumn('users', 'preferred_admin_language_id')) {
            return;
        }

        SetUserPreferredAdminLanguageAction::run($record, $this->preferredAdminLanguageId);
    }

    private function userSchemaContext(?Model $record = null): UserSchemaContextData
    {
        if (! $record instanceof Model) {
            $roleNames = [];

            return UserSchemaContextData::forCreate(
                roleNames: $roleNames,
                schemaType: ResolveUserSchemaTypeAction::run($roleNames),
                resourceName: 'users',
            );
        }

        $roleNames = $this->resolveUserRoleNames($record);

        return UserSchemaContextData::forEdit(
            record: $record,
            roleNames: $roleNames,
            schemaType: ResolveUserSchemaTypeAction::run($roleNames),
            resourceName: 'users',
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolveUserRoleNames(Model $record): array
    {
        if (! method_exists($record, 'roles')) {
            return [];
        }

        $record->loadMissing('roles');

        return $record->getRelationValue('roles')
            ?->pluck('name')
            ->filter(fn (mixed $roleName): bool => is_string($roleName))
            ->values()
            ->all() ?? [];
    }
}
