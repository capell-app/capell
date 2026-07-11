<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Users\Schemas;

use Capell\Admin\Actions\Users\ListAdminLanguageOptionsAction;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Filament\Components\Forms\FixedWidthSidebar;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\HasMedia;

class UserForm implements FormConfigurator
{
    public static function configure(Schema $schema, mixed $context = null): Schema
    {
        $context = $context instanceof UserSchemaContextData
            ? $context
            : UserSchemaContextData::forCreate();

        return $schema->components(static::getFormSchema($schema, $context))->columns();
    }

    /**
     * @return array<int, Component>
     */
    protected static function getFormSchema(Schema $schema, UserSchemaContextData $context): array
    {
        $pipeline = resolve(AdminSchemaExtensionPipeline::class);

        $identityFields = [
            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::BeforeIdentity, $context),

            TextInput::make('name')
                ->label(__('capell-admin::form.name'))
                ->required(),

            TextInput::make('email')
                ->label(__('capell-admin::form.email'))
                ->email()
                ->required(),

            ...self::adminLanguageField(),

            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::AfterIdentity, $context),
        ];

        $credentialFields = [
            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::BeforeCredentials, $context),

            TextInput::make('password')
                ->label(__('capell-admin::form.password'))
                ->helperText(__('capell-admin::generic.user_password_info'))
                ->password()
                ->maxLength(250)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create'),

            TextInput::make('password_confirmation')
                ->label(__('capell-admin::form.password_confirmation'))
                ->helperText(__('capell-admin::generic.user_password_confirmation_info'))
                ->password()
                ->maxLength(250)
                ->dehydrated(false)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->requiredWith('password')
                ->same('password'),

            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::AfterCredentials, $context),
        ];

        $roleFields = [
            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::BeforeRoles, $context),

            Select::make('roles')
                ->label(__('capell-admin::form.roles'))
                ->helperText(__('capell-admin::generic.user_roles_info'))
                ->relationship(name: 'roles', titleAttribute: 'name')
                ->preload()
                ->multiple()
                ->live()
                ->visible(fn (): bool => self::canManageRoles())
                ->dehydrated(false)
                ->saveRelationshipsUsing(function (Model $record, mixed $state): void {
                    if (! self::canManageRoles() || ! method_exists($record, 'roles')) {
                        return;
                    }

                    $roleIds = is_array($state) ? $state : [];

                    $record->roles()->sync($roleIds);
                }),

            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::AfterRoles, $context),
        ];

        $profileFields = [
            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::BeforeProfile, $context),
        ];

        if (self::userModelSupportsMedia()) {
            $profileFields[] = MediaLibraryFileUpload::make('profile_image')
                ->label(__('capell-admin::form.profile_image'));
        }

        $profileFields[] = Textarea::make('bio')
            ->label(__('capell-admin::form.biography'))
            ->columnSpanFull();

        $profileFields = [
            ...$profileFields,
            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::AfterProfile, $context),
        ];

        $mainSections = [
            Section::make(__('capell-admin::form.user_identity'))
                ->description(__('capell-admin::generic.user_identity_description'))
                ->columns()
                ->columnSpanFull()
                ->schema($identityFields),

            Section::make(__('capell-admin::form.user_credentials'))
                ->description(__('capell-admin::generic.user_credentials_description'))
                ->columns()
                ->columnSpanFull()
                ->schema($credentialFields),

            Section::make(__('capell-admin::form.roles'))
                ->description(__('capell-admin::generic.user_roles_description'))
                ->columns()
                ->columnSpanFull()
                ->schema($roleFields),

            Section::make(__('capell-admin::generic.profile'))
                ->description(__('capell-admin::generic.user_profile_description'))
                ->columns()
                ->columnSpanFull()
                ->schema($profileFields),

            ...$pipeline->userComponentsForHook($schema, UserSchemaHookEnum::Footer, $context),
        ];

        $sidebarComponents = $pipeline->userSidebarComponents($schema, $context);

        if ($sidebarComponents === []) {
            return $mainSections;
        }

        return [
            FixedWidthSidebar::make()
                ->mainSchema($mainSections)
                ->sidebarSchema($sidebarComponents),
        ];
    }

    private static function userModelSupportsMedia(): bool
    {
        $userModel = config('auth.providers.users.model');

        return is_string($userModel) && is_subclass_of($userModel, HasMedia::class);
    }

    /**
     * @return array<int, Component>
     */
    private static function adminLanguageField(): array
    {
        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable('users') || ! $schema->hasColumn('users', 'preferred_admin_language_id')) {
            return [];
        }

        return [
            Select::make('preferred_admin_language_id')
                ->label(__('capell-admin::form.preferred_admin_language'))
                ->helperText(__('capell-admin::form.preferred_admin_language_helper'))
                ->options(fn (): array => ListAdminLanguageOptionsAction::run()->all())
                ->rules([
                    'nullable',
                    Rule::exists('languages', 'id')->where('status', true),
                ])
                ->searchable()
                ->preload()
                ->nullable(),
        ];
    }

    private static function canManageRoles(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if ($actor->isGlobalAdmin()) {
            return true;
        }

        return $actor->hasRole(config('capell.roles.super_admin', 'super_admin'));
    }
}
