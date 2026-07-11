<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;
use Capell\Admin\Support\Bridges\UserResourceBridgeResolver;
use Capell\Admin\Tests\Fixtures\Autoload\FirstRelationManagerForResolverTest;
use Capell\Admin\Tests\Fixtures\Autoload\FullUserResourceBridgeForResolverTest;
use Capell\Admin\Tests\Fixtures\Autoload\SecondRelationManagerForResolverTest;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('resolves enabled bridge schema components for a hook', function (): void {
    app()->bind('test.user-resource-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
        {
            return $hook === UserSchemaHookEnum::AfterIdentity
                ? [TextInput::make('bridge_field')]
                : [];
        }
    });

    app()->tag(['test.user-resource-bridge'], UserResourceBridge::TAG);

    $resolver = new UserResourceBridgeResolver;
    $components = $resolver->resolveComponentsForHook(
        Schema::make(),
        UserSchemaHookEnum::AfterIdentity,
        UserSchemaContextData::forCreate(),
    );

    expect($components)->toHaveCount(1)
        ->and($components[0]->getName())->toBe('bridge_field');
});

it('filters out unsupported bridges', function (): void {
    app()->bind('test.supported-user-resource-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
        {
            return [TextInput::make('supported_field')];
        }
    });

    app()->bind('test.unsupported-user-resource-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function supports(UserSchemaContextData $context): bool
        {
            return false;
        }

        public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
        {
            return [TextInput::make('unsupported_field')];
        }
    });

    app()->tag([
        'test.supported-user-resource-bridge',
        'test.unsupported-user-resource-bridge',
    ], UserResourceBridge::TAG);

    $resolver = new UserResourceBridgeResolver;
    $components = $resolver->resolveSidebarComponents(Schema::make(), UserSchemaContextData::forCreate());

    expect($components)->toHaveCount(1)
        ->and($components[0]->getName())->toBe('supported_field');
});

it('resolves table and lifecycle contributions from one bridge', function (): void {
    app()->bind('test.full-user-resource-bridge', fn (): UserResourceBridge => new FullUserResourceBridgeForResolverTest);
    app()->tag(['test.full-user-resource-bridge'], UserResourceBridge::TAG);

    $resolver = new UserResourceBridgeResolver;
    $record = new class extends Model
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;
    };
    $createContext = UserSchemaContextData::forCreate(resourceName: 'users');
    $editContext = UserSchemaContextData::forEdit($record, [], 'default', 'users');

    $createData = $resolver->mutateDataBeforeCreate(['name' => 'Ada'], $createContext);
    $saveData = $resolver->mutateDataBeforeSave($record, ['name' => 'Ada'], $editContext);
    $resolver->afterCreate($record, $editContext);
    $resolver->afterSave($record, $editContext);

    expect($createData['bridge_created'])->toBeTrue()
        ->and($saveData['bridge_saved'])->toBeTrue()
        ->and($record->getAttribute('bridge_after_create'))->toBeTrue()
        ->and($record->getAttribute('bridge_after_save'))->toBeTrue()
        ->and($resolver->columns($createContext)[0])->toBeInstanceOf(TextColumn::class)
        ->and($resolver->filters($createContext)[0])->toBeInstanceOf(Filter::class)
        ->and($resolver->recordActions($createContext)[0])->toBeInstanceOf(Action::class)
        ->and($resolver->toolbarActions($createContext)[0])->toBeInstanceOf(Action::class);
});

it('honors explicit lifecycle context when bridge support depends on schema type', function (): void {
    app()->bind('test.schema-type-user-resource-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function supports(UserSchemaContextData $context): bool
        {
            return $context->isSchemaType('security');
        }

        public function mutateDataBeforeCreate(array $data): array
        {
            $data['security_context_applied'] = true;

            return $data;
        }
    });

    app()->tag(['test.schema-type-user-resource-bridge'], UserResourceBridge::TAG);

    $resolver = new UserResourceBridgeResolver;

    expect($resolver->mutateDataBeforeCreate(
        ['name' => 'Ada'],
        UserSchemaContextData::forCreate(schemaType: 'default', resourceName: 'users'),
    ))->not->toHaveKey('security_context_applied')
        ->and($resolver->mutateDataBeforeCreate(
            ['name' => 'Ada'],
            UserSchemaContextData::forCreate(schemaType: 'security', resourceName: 'users'),
        ))->toHaveKey('security_context_applied', true);
});

it('honors explicit table context when bridge support depends on role names', function (): void {
    app()->bind('test.role-user-resource-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function supports(UserSchemaContextData $context): bool
        {
            return $context->hasRole('manager');
        }

        public function columns(): array
        {
            return [TextColumn::make('manager_column')];
        }
    });

    app()->tag(['test.role-user-resource-bridge'], UserResourceBridge::TAG);

    $resolver = new UserResourceBridgeResolver;

    expect($resolver->columns(UserSchemaContextData::forCreate(
        roleNames: ['editor'],
        resourceName: 'users',
    )))->toBe([])
        ->and($resolver->columns(UserSchemaContextData::forCreate(
            roleNames: ['manager'],
            resourceName: 'users',
        ))[0])->toBeInstanceOf(TextColumn::class);
});

it('applies and de-duplicates relation manager contributions', function (): void {
    app()->bind('test.relation-manager-bridge', fn (): UserResourceBridge => new class extends AbstractUserResourceBridge
    {
        public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
        {
            return [
                ...$relationManagers,
                FirstRelationManagerForResolverTest::class,
                SecondRelationManagerForResolverTest::class,
                FirstRelationManagerForResolverTest::class,
            ];
        }
    });

    app()->tag(['test.relation-manager-bridge'], UserResourceBridge::TAG);

    $resolver = new UserResourceBridgeResolver;
    $record = new class extends Model
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;
    };

    expect($resolver->resolveRelationManagers(
        $record,
        [
            FirstRelationManagerForResolverTest::class,
        ],
        UserSchemaContextData::forEdit($record, [], 'default', 'users'),
    ))->toBe([
        FirstRelationManagerForResolverTest::class,
        SecondRelationManagerForResolverTest::class,
    ]);
});
