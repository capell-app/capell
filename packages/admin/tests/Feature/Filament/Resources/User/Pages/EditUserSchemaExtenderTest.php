<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\UserFormExtender;
use Capell\Admin\Contracts\Extenders\UserSchemaExtender;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\Admin\Support\Schemas\AbstractUserSchemaExtender;
use Capell\Admin\Tests\Fixtures\Autoload\HiddenUserSchemaExtenderTestRelationManager;
use Capell\Admin\Tests\Fixtures\Autoload\VisibleUserSchemaExtenderTestRelationManager;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)->group('user');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('passes role-derived edit context to supported schema extenders', function (): void {
    config()->set('capell-admin.user_resource.role_schema_types', [
        'editor' => 'editorial',
    ]);

    $editorRole = Role::findOrCreate('editor');
    $user = UserFactory::new()->createOne(['name' => 'Editor User']);
    $user->assignRole($editorRole);

    app()->bind('user-edit-schema-extender', fn (): UserSchemaExtender => new class extends AbstractUserSchemaExtender
    {
        public function supports(UserSchemaContextData $context): bool
        {
            return $context->isSchemaType('editorial') && $context->hasRole('editor');
        }

        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            if ($hook !== UserSchemaHookEnum::AfterProfile) {
                return [];
            }

            return [TextInput::make('editor_signature')];
        }
    });
    app()->tag(['user-edit-schema-extender'], UserSchemaExtender::TAG);

    app()->bind('user-edit-form-extender', fn (): UserFormExtender => new class implements UserFormExtender
    {
        public function mutateDataBeforeCreate(array $data): array
        {
            return $data;
        }

        public function afterCreate(Model $record): void {}

        public function mutateDataBeforeSave(Model $record, array $data): array
        {
            $data['bio'] = $data['editor_signature'] ?? null;
            unset($data['editor_signature']);

            return $data;
        }

        public function afterSave(Model $record): void {}
    });
    app()->tag(['user-edit-form-extender'], UserFormExtender::TAG);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldExists('editor_signature')
        ->fillForm([
            'name' => 'Editor User',
            'email' => $user->email,
            'editor_signature' => 'Edited by schema extender',
            'roles' => [$editorRole->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->getAttribute('bio'))->toBe('Edited by schema extender');
});

it('does not render unsupported role-specific schema extender fields', function (): void {
    config()->set('capell-admin.user_resource.role_schema_types', [
        'editor' => 'editorial',
    ]);

    $user = UserFactory::new()->createOne();

    app()->bind('unsupported-user-edit-schema-extender', fn (): UserSchemaExtender => new class extends AbstractUserSchemaExtender
    {
        public function supports(UserSchemaContextData $context): bool
        {
            return $context->isSchemaType('editorial') && $context->hasRole('editor');
        }

        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            return [TextInput::make('editor_signature')];
        }
    });
    app()->tag(['unsupported-user-edit-schema-extender'], UserSchemaExtender::TAG);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldDoesNotExist('editor_signature');
});

it('renders sidebar components through the fixed width sidebar layout', function (): void {
    $user = UserFactory::new()->createOne();

    app()->bind('user-sidebar-schema-extender', fn (): UserSchemaExtender => new class extends AbstractUserSchemaExtender
    {
        public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
        {
            return [
                Section::make('Bridge Summary')
                    ->schema([
                        TextInput::make('sidebar_marker'),
                    ]),
            ];
        }
    });
    app()->tag(['user-sidebar-schema-extender'], UserSchemaExtender::TAG);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldExists('sidebar_marker');
});

it('includes extender relation managers before Filament filters them for the edited record', function (): void {
    $user = UserFactory::new()->createOne();

    app()->bind('user-relation-schema-extender', fn (): UserSchemaExtender => new class extends AbstractUserSchemaExtender
    {
        public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
        {
            return [
                ...$relationManagers,
                VisibleUserSchemaExtenderTestRelationManager::class,
                HiddenUserSchemaExtenderTestRelationManager::class,
            ];
        }
    });
    app()->tag(['user-relation-schema-extender'], UserSchemaExtender::TAG);

    $page = Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->instance();

    assert($page instanceof EditUser);

    $relationManagers = $page->getRelationManagers();

    expect($relationManagers)
        ->toContain(VisibleUserSchemaExtenderTestRelationManager::class)
        ->not->toContain(HiddenUserSchemaExtenderTestRelationManager::class);
});
