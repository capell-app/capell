<?php

declare(strict_types=1);

use Capell\Admin\Enums\BlueprintCreationModeEnum;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Actions\Blueprint\CreateBlueprintAction;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Database\Factories\BlueprintFactory;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('type');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can list types', function (): void {
    $types = Blueprint::factory()->count(5)->create();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords($types->count())
        ->assertCanSeeTableRecords($types);
});

it('can filter type', function (): void {
    Blueprint::factory()->page()->create();

    $types = Blueprint::factory()->site()->count(5)->create();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords(6)
        ->assertCanSeeTableRecords($types)
        ->set('activeTab', BlueprintSubjectEnum::Page->value)
        ->assertCountTableRecords(1);
});

it('can search types', function (): void {
    $types = Blueprint::factory()
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Blueprint(%d)', $sequence->index)])
        ->count(3)
        ->create();

    $name = $types->random()->name;

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->searchTable($name)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords($types->where('name', $name))
        ->assertCanNotSeeTableRecords($types->where('name', '!=', $name));
});

it('ranks blueprint name matches before key matches', function (): void {
    $search = 'capell-blueprint-table-relevance';

    $nameMatch = Blueprint::factory()->createOne([
        'name' => $search . ' name match',
        'key' => 'blueprint-name-match',
    ]);
    $keyMatch = Blueprint::factory()->createOne([
        'name' => 'Z blueprint key match',
        'key' => $search . '-key-match',
    ]);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->searchTable($search)
        ->assertCanSeeTableRecords([$nameMatch, $keyMatch], inOrder: true);
});

it('keeps explicit blueprint table sorting ahead of search relevance', function (): void {
    $search = 'capell-blueprint-table-explicit-sort';

    $nameMatch = Blueprint::factory()->createOne([
        'name' => 'Z ' . $search . ' name match',
        'key' => 'blueprint-name-match',
    ]);
    $keyMatch = Blueprint::factory()->createOne([
        'name' => 'A blueprint key match',
        'key' => $search . '-key-match',
    ]);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->searchTable($search)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$keyMatch, $nameMatch], inOrder: true);
});

it('can sort types', function (): void {
    $types = Blueprint::factory()
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Blueprint(%02d)', $sequence->index)])
        ->count(10)
        ->create();

    $sorted = Blueprint::query()->orderBy('name')->pluck('id');

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords($types->count())
        ->sortTable('name')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

it('can replicate type', function (): void {
    $type = Blueprint::factory()->createOne();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callTableAction(
            'replicate',
            $type,
            [
                'name' => $type->name . ' (copy)',
                'key' => $type->key . '-copy',
            ],
        )
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    assertDatabaseHas('blueprints', [
        'name' => $type->name . ' (copy)',
        'key' => $type->key . '-copy',
    ]);
});

it('can create type', function (BlueprintSubjectEnum $type): void {
    $record = Blueprint::factory()->make();

    $hasTypeConfigurator = AdminSurfaceLookup::hasConfigurator(ConfiguratorTypeEnum::Blueprint, $type->getKey());

    $admin = $record->admin;

    if ($hasTypeConfigurator) {
        $admin['type_configurator'] = $type->name;
    }

    Blueprint::query()->create(mutateCreateBlueprintActionData([
        'creation_mode' => BlueprintCreationModeEnum::Custom->value,
        'type' => $type->value,
        'name' => $record->name,
        'key' => $record->key,
        'meta' => [
            'component' => 'example-content',
        ],
        'admin' => [
            ...$admin,
            'configurator' => 'Default',
        ],
    ]));

    assertDatabaseHas('blueprints', [
        'name' => $record->name,
        'key' => $record->key,
    ]);
})->with(BlueprintSubjectEnum::cases());

it('can create basic type from the primary create action', function (): void {
    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertActionExists('create')
        ->assertActionDoesNotExist('makeType');

    Blueprint::query()->create(mutateCreateBlueprintActionData([
        'creation_mode' => BlueprintCreationModeEnum::Basic->value,
        'type' => BlueprintSubjectEnum::Page->value,
        'name' => 'Product',
        'key' => 'product',
        'status' => false,
        'admin' => [
            'icon' => 'heroicon-o-shopping-bag',
            'notes' => 'Product catalogue pages',
            'configurator' => 'Ignored',
        ],
    ]));

    $type = expectPresent(Blueprint::query()->firstWhere('key', 'product'));

    expect($type)
        ->not->toBeNull()
        ->and($type->status)->toBeFalse()
        ->and($type->default)->toBeFalse()
        ->and($type->admin)->toBe([
            'icon' => 'heroicon-o-shopping-bag',
            'notes' => 'Product catalogue pages',
        ])
        ->and($type->meta)->toBeNull();
});

it('basic type creation shows editor fields and keeps developer settings behind custom mode', function (): void {
    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->mountAction('create')
        ->assertSchemaComponentVisible('admin.icon')
        ->assertSchemaComponentVisible('admin.notes')
        ->assertSchemaComponentVisible('status')
        ->assertSchemaComponentHidden('admin.type_configurator')
        ->assertSchemaComponentHidden('admin.configurator')
        ->fillForm([
            'creation_mode' => BlueprintCreationModeEnum::Custom->value,
        ])
        ->assertSchemaComponentVisible('admin.type_configurator')
        ->assertSchemaComponentVisible('admin.configurator');
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function mutateCreateBlueprintActionData(array $data): array
{
    $method = new ReflectionMethod(CreateBlueprintAction::class, 'mutateFormData');

    return $method->invoke(CreateBlueprintAction::make('create'), $data);
}

it('can create and edit page blueprints with admin and frontend meta fields', function (): void {
    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->mountAction('create')
        ->fillForm([
            'creation_mode' => BlueprintCreationModeEnum::Custom->value,
            'type' => BlueprintSubjectEnum::Page->value,
            'admin' => [
                'type_configurator' => 'page',
            ],
        ])
        ->assertSchemaStateSet([
            'type' => BlueprintSubjectEnum::Page->value,
            'admin.type_configurator' => 'page',
        ]);

    Blueprint::query()->create(mutateCreateBlueprintActionData([
        'creation_mode' => BlueprintCreationModeEnum::Custom->value,
        'type' => BlueprintSubjectEnum::Page->value,
        'name' => 'Landing page',
        'key' => 'landing-page',
        'group' => 'marketing',
        'meta' => [
            'component' => 'capell.page.landing',
            'cache_time' => 'weekly',
            'accessible' => true,
            'listable' => true,
            'sitemap' => true,
            'layout_editable' => true,
        ],
        'admin' => [
            'type_configurator' => 'page',
            'configurator' => 'Landing',
            'icon' => 'heroicon-o-document-text',
        ],
        'status' => true,
        'default' => false,
    ]));

    $type = expectPresent(Blueprint::query()->firstWhere('key', 'landing-page'));

    expect($type)
        ->not->toBeNull()
        ->and($type->component)->toBe('capell.page.landing')
        ->and($type->meta)->toMatchArray([
            'cache_time' => 'weekly',
            'accessible' => true,
            'listable' => true,
            'sitemap' => true,
            'layout_editable' => true,
        ])
        ->and($type->admin)->toMatchArray([
            'type_configurator' => 'page',
            'configurator' => 'Landing',
            'icon' => 'heroicon-o-document-text',
        ]);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->mountTableAction('edit', $type)
        ->assertSchemaStateSet([
            'component' => 'capell.page.landing',
            'meta.layout_editable' => true,
            'admin.type_configurator' => 'page',
            'admin.configurator' => 'Landing',
        ]);
});

it('can edit database-backed page type fields from the admin form', function (): void {
    $type = Blueprint::factory()->page()->create([
        'name' => 'Original landing page',
        'key' => 'original-landing-page',
        'group' => 'original',
        'order' => 3,
        'default' => false,
        'status' => true,
        'component' => 'capell.page.original',
        'meta' => [
            'cache_time' => 'daily',
            'cache_frequency' => 'always',
            'accessible' => true,
            'listable' => true,
            'sitemap' => true,
            'layout_editable' => true,
        ],
        'admin' => [
            'type_configurator' => 'page',
            'configurator' => 'Default',
            'icon' => 'heroicon-o-document-text',
            'notes' => 'Original notes',
        ],
    ]);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->mountTableAction('edit', $type)
        ->assertSchemaStateSet([
            'name' => 'Original landing page',
            'key' => 'original-landing-page',
            'type' => BlueprintSubjectEnum::Page->value,
            'group' => 'original',
            'component' => 'capell.page.original',
            'meta.cache_time' => 'daily',
            'meta.cache_frequency' => 'always',
            'meta.accessible' => true,
            'meta.listable' => true,
            'meta.sitemap' => true,
            'meta.layout_editable' => true,
            'admin.type_configurator' => 'page',
            'admin.configurator' => 'Default',
            'admin.icon' => 'heroicon-o-document-text',
            'admin.notes' => 'Original notes',
            'order' => 3,
            'default' => false,
            'status' => true,
        ]);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction('edit', $type, [
            'creation_mode' => BlueprintCreationModeEnum::Custom->value,
            'name' => 'Updated landing page',
            'key' => 'updated-landing-page',
            'type' => BlueprintSubjectEnum::Page->value,
            'group' => 'marketing',
            'component' => 'capell.page.updated',
            'meta' => [
                'cache_time' => 'weekly',
                'cache_frequency' => 'always',
                'accessible' => false,
                'listable' => false,
                'sitemap' => false,
                'with_next_prev' => true,
                'layout_editable' => false,
                'content_structure' => 'blocks',
            ],
            'admin' => [
                'type_configurator' => 'page',
                'configurator' => 'Landing',
                'icon' => 'heroicon-o-rocket-launch',
                'notes' => 'Updated notes',
            ],
            'order' => 12,
            'default' => true,
            'status' => '0',
        ])
        ->assertHasNoFormErrors();

    expect($type->refresh())
        ->name->toBe('Updated landing page')
        ->key->toBe('updated-landing-page')
        ->group->toBe('marketing')
        ->component->toBe('capell.page.updated')
        ->meta->toMatchArray([
            'cache_time' => 'weekly',
            'cache_frequency' => 'always',
            'accessible' => false,
            'listable' => false,
            'sitemap' => false,
            'with_next_prev' => true,
            'layout_editable' => false,
            'content_structure' => 'blocks',
        ])
        ->admin->toMatchArray([
            'type_configurator' => 'page',
            'configurator' => 'Landing',
            'icon' => 'heroicon-o-rocket-launch',
            'notes' => 'Updated notes',
        ])
        ->order->toBe(12)
        ->default->toBeTrue()
        ->status->toBeFalse();
});

it('hydrates the default admin configurator when editing a created type', function (): void {
    Blueprint::query()->create(mutateCreateBlueprintActionData([
        'creation_mode' => BlueprintCreationModeEnum::Basic->value,
        'type' => BlueprintSubjectEnum::Page->value,
        'name' => 'Article',
        'key' => 'article',
    ]));

    $type = Blueprint::query()->firstWhere('key', 'article');

    expect($type)->not->toBeNull();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->mountTableAction('edit', $type)
        ->assertSchemaStateSet([
            'admin.configurator' => 'Default',
        ]);
});

it('can not create type', function (BlueprintSubjectEnum $type): void {
    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callAction(
            'create',
            [
                'creation_mode' => BlueprintCreationModeEnum::Basic->value,
                'type' => $type->value,
                'name' => '',
                'key' => '',
            ],
        )
        ->assertHasFormErrors([
            'name' => ['required'],
            'key' => ['required'],
        ])
        ->assertCountTableRecords(0);
})->with(BlueprintSubjectEnum::cases());

it('can update type', function (BlueprintSubjectEnum $typeEnum): void {
    $type = Blueprint::factory()
        ->type($typeEnum)
        ->when(
            AdminSurfaceLookup::hasConfigurator(ConfiguratorTypeEnum::Blueprint, $typeEnum->getKey()),
            fn (BlueprintFactory $factory): BlueprintFactory => $factory->adminTypeConfigurator($typeEnum->getKey()),
        )
        ->create();

    $newType = Blueprint::factory()->make();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction(
            'edit',
            $type,
            [
                'name' => $newType->name,
                'key' => $newType->key,
            ],
        )
        ->assertHasNoFormErrors();

    expect($type->refresh())
        ->name->toBe($newType->name)
        ->key->toBe($newType->key);
})->with(BlueprintSubjectEnum::cases());

it('can not update page role restrictions without the manage restrictions permission', function (): void {
    Permission::findOrCreate(CapellPermission::ManagePageRestrictions->name());

    $role = Role::findOrCreate('client_editor');
    $type = Blueprint::factory()
        ->page()
        ->adminTypeConfigurator('page')
        ->create();

    test()->actingAsUser();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction(
            'edit',
            $type,
            [
                'name' => $type->name,
                'key' => $type->key,
                'type' => BlueprintSubjectEnum::Page->value,
                'admin' => [
                    'type_configurator' => 'page',
                    'configurator' => 'Default',
                    'role_restrictions' => [$role->getKey()],
                ],
            ],
        )
        ->assertHasNoFormErrors();

    expect($type->refresh()->getRestrictedRoleIds()->all())->toBe([]);
});

it('can update page role restrictions with the manage restrictions permission', function (): void {
    Permission::findOrCreate(CapellPermission::ManagePageRestrictions->name());

    $role = Role::findOrCreate('client_editor');
    $type = Blueprint::factory()
        ->page()
        ->adminTypeConfigurator('page')
        ->create();

    $user = test()->createUserWithPermission(CapellPermission::ManagePageRestrictions->name());
    test()->actingAs($user);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction(
            'edit',
            $type,
            [
                'name' => $type->name,
                'key' => $type->key,
                'type' => BlueprintSubjectEnum::Page->value,
                'admin' => [
                    'type_configurator' => 'page',
                    'configurator' => 'Default',
                    'role_restrictions' => [$role->getKey()],
                ],
            ],
        )
        ->assertHasNoFormErrors();

    expect($type->refresh()->getRestrictedRoleIds()->all())->toBe([$role->getKey()]);
});

it('can update whether page layouts are editable', function (): void {
    $type = Blueprint::factory()
        ->page()
        ->adminTypeConfigurator('page')
        ->create();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction(
            'edit',
            $type,
            [
                'name' => $type->name,
                'key' => $type->key,
                'type' => BlueprintSubjectEnum::Page->value,
                'meta' => [
                    'layout_editable' => false,
                ],
                'admin' => [
                    'type_configurator' => 'page',
                    'configurator' => 'Default',
                ],
            ],
        )
        ->assertHasNoFormErrors();

    expect($type->refresh())
        ->getMeta('layout_editable')->toBeFalse();
});

it('can not update type', function (): void {
    $type = Blueprint::factory()->createOne();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction(
            'edit',
            $type,
            [
                'name' => '',
                'key' => '',
            ],
        )
        ->assertHasFormErrors([
            'name' => ['required'],
            'key' => ['required'],
        ]);
});

it('can delete type', function (): void {
    $type = Blueprint::factory()->createOne();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callTableAction('delete', $type)
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(0);

    assertSoftDeleted($type, ['id' => $type->id]);
});

it('can group delete types', function (): void {
    $types = Blueprint::factory()->count(5)->create();

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableBulkAction('delete', $types)
        ->assertHasNoFormErrors();

    foreach ($types as $type) {
        assertSoftDeleted($type, ['id' => $type->id]);
    }
});
