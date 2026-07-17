<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Filament\Resources\Users\Pages\CreateUser;
use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)->group('user');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('renders and persists a create field through one user resource bridge', function (): void {
    app()->bind('user-create-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            if ($hook !== UserSchemaHookEnum::AfterIdentity) {
                return [];
            }

            return [TextInput::make('external_reference')];
        }

        public function mutateDataBeforeCreate(array $data): array
        {
            $data['bio'] = $data['external_reference'] ?? null;
            unset($data['external_reference']);

            return $data;
        }
    });
    app()->tag(['user-create-bridge'], UserResourceBridge::TAG);

    Livewire::test(CreateUser::class)
        ->assertFormFieldExists('external_reference')
        ->fillForm([
            'name' => 'Schema Extended User',
            'email' => 'schema-extended-user@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'external_reference' => 'EXT-123',
            'roles' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('users', [
        'name' => 'Schema Extended User',
        'email' => 'schema-extended-user@example.test',
        'bio' => 'EXT-123',
    ]);
});
