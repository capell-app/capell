<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\UserFormExtender;
use Capell\Admin\Contracts\Extenders\UserSchemaExtender;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Filament\Resources\Users\Pages\CreateUser;
use Capell\Admin\Support\Schemas\AbstractUserSchemaExtender;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)->group('user');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('renders and persists a create schema extender field through the user form extender lifecycle', function (): void {
    app()->bind('user-create-schema-extender', fn (): UserSchemaExtender => new class extends AbstractUserSchemaExtender
    {
        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            if ($hook !== UserSchemaHookEnum::AfterIdentity) {
                return [];
            }

            return [TextInput::make('external_reference')];
        }
    });
    app()->tag(['user-create-schema-extender'], UserSchemaExtender::TAG);

    app()->bind('user-create-form-extender', fn (): UserFormExtender => new class implements UserFormExtender
    {
        public function mutateDataBeforeCreate(array $data): array
        {
            $data['bio'] = $data['external_reference'] ?? null;
            unset($data['external_reference']);

            return $data;
        }

        public function afterCreate(Model $record): void {}

        public function mutateDataBeforeSave(Model $record, array $data): array
        {
            return $data;
        }

        public function afterSave(Model $record): void {}
    });
    app()->tag(['user-create-form-extender'], UserFormExtender::TAG);

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
