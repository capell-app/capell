<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;
use Capell\Admin\Tests\Fixtures\Autoload\HiddenUserResourceBridgeTestRelationManager;
use Capell\Admin\Tests\Fixtures\Autoload\VisibleUserResourceBridgeTestRelationManager;
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

it('passes role-derived edit context to supported user resource bridges', function (): void {
    config()->set('capell-admin.user_resource.role_schema_types', [
        'editor' => 'editorial',
    ]);

    $editorRole = Role::findOrCreate('editor');
    $user = UserFactory::new()->createOne(['name' => 'Editor User']);
    $user->assignRole($editorRole);

    app()->bind('user-edit-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
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

        public function mutateDataBeforeSave(Model $record, array $data): array
        {
            $data['bio'] = $data['editor_signature'] ?? null;
            unset($data['editor_signature']);

            return $data;
        }
    });
    app()->tag(['user-edit-bridge'], UserResourceBridge::TAG);

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

it('does not render unsupported role-specific bridge fields', function (): void {
    config()->set('capell-admin.user_resource.role_schema_types', [
        'editor' => 'editorial',
    ]);

    $user = UserFactory::new()->createOne();

    app()->bind('unsupported-user-edit-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
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
    app()->tag(['unsupported-user-edit-bridge'], UserResourceBridge::TAG);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldDoesNotExist('editor_signature');
});

it('renders sidebar components through the fixed width sidebar layout', function (): void {
    $user = UserFactory::new()->createOne();

    app()->bind('user-sidebar-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
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
    app()->tag(['user-sidebar-bridge'], UserResourceBridge::TAG);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldExists('sidebar_marker');
});

it('includes bridge relation managers before Filament filters them for the edited record', function (): void {
    $user = UserFactory::new()->createOne();

    app()->bind('user-relation-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
        {
            return [
                ...$relationManagers,
                VisibleUserResourceBridgeTestRelationManager::class,
                HiddenUserResourceBridgeTestRelationManager::class,
            ];
        }
    });
    app()->tag(['user-relation-bridge'], UserResourceBridge::TAG);

    $page = Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->instance();

    assert($page instanceof EditUser);

    $relationManagers = $page->getRelationManagers();

    expect($relationManagers)
        ->toContain(VisibleUserResourceBridgeTestRelationManager::class)
        ->not->toContain(HiddenUserResourceBridgeTestRelationManager::class);
});
