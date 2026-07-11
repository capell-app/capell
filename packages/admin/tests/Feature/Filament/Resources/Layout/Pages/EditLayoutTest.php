<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\DeleteAction;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\LayoutGroupEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\LayoutCreator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('layout');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can retrieve data', function (): void {
    $layout = Layout::factory()->createOne();

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.layout_identity'))
        ->assertSee(__('capell-admin::generic.layout_identity_description'))
        ->assertSee(__('capell-admin::generic.key_label'))
        ->assertSee(__('capell-admin::form.layout_availability'))
        ->assertSee(__('capell-admin::generic.layout_availability_description'))
        ->assertSee(__('capell-admin::generic.layout_theme_info'))
        ->assertSee(__('capell-admin::generic.layout_files_description'))
        ->assertSee(__('capell-admin::generic.layout_master_file_info'))
        ->assertSee(__('capell-admin::generic.layout_layout_file_info'))
        ->assertSchemaStateSet([
            'name' => $layout->name,
            'key' => $layout->key,
            'group' => $layout->group,
        ]);
});

it('can save', function (): void {
    $layout = Layout::factory()->createOne();
    $theme = Theme::factory()->createOne();

    $newData = Layout::factory()
        ->site(Site::factory()->createOne())
        ->state(['theme_id' => $theme->getKey()])
        ->make();

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'key' => $newData->key,
            'group' => $newData->group,
            'site_id' => $newData->site->getKey(),
            'theme_id' => $newData->theme_id,
            'meta' => [
                'master_file' => 'page.updated',
                'layout_file' => 'layout.updated',
            ],
            'order' => 14,
            'default' => true,
            'status' => '0',
        ])
        ->assertSchemaStateSet([
            'name' => $newData->name,
            'key' => $newData->key,
            'group' => $newData->group,
            'site_id' => $newData->site->getKey(),
            'theme_id' => $newData->theme_id,
            'meta.master_file' => 'page.updated',
            'meta.layout_file' => 'layout.updated',
            'order' => 14,
            'default' => true,
            'status' => '0',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($layout->refresh())
        ->name->toBe($newData->name)
        ->key->toBe($newData->key)
        ->group->toBe($newData->group)
        ->site_id->toBe($newData->site->getKey())
        ->theme_id->toBe($newData->theme_id)
        ->meta->toMatchArray([
            'master_file' => 'page.updated',
            'layout_file' => 'layout.updated',
        ])
        ->order->toBe(14)
        ->default->toBeTrue()
        ->status->toBeFalse();
});

it('hydrates legacy layouts without groups to the default group', function (): void {
    $layout = Layout::factory()->createOne([
        'group' => null,
    ]);

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSchemaStateSet([
            'group' => LayoutGroupEnum::Default->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($layout->refresh()->group)->toBe(LayoutGroupEnum::Default->value);
});

test('validates edit layout', function (): void {
    $layout = Layout::factory()->createOne();

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => null,
            'key' => null,
            'group' => null,
        ])
        ->call('save')
        ->assertHasFormErrors([
            'name' => 'required',
            'key' => 'required',
            'group' => 'required',
        ]);
});

it('can delete', function (): void {
    $layout = Layout::factory()->createOne();

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction(DeleteAction::class)
        ->assertHasNoFormErrors()
        // TODO bug in filament does not check if halted action
        // ->assertActionHalted(DeleteAction::class)
        ->assertNotDispatched('delete-action-halted');

    assertSoftDeleted($layout, ['id' => $layout->id]);
});

test('can not delete layout if it is used', function (): void {
    $layout = Layout::factory()->createOne();
    Page::factory()->layout($layout)->create();

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction(DeleteAction::class)
        ->assertNotified(__(
            'capell-admin::message.layout_not_deletable',
            ['name' => $layout->name],
        ));

    assertDatabaseHas($layout, ['id' => $layout->id]);
});

test('can edit layouts', function (LayoutEnum $layoutEnum): void {
    $layout = resolve(LayoutCreator::class)->create($layoutEnum);

    $newData = Layout::factory()->make();

    Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'key' => $newData->key,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Layout::class, [
        'name' => $newData->name,
        'key' => $newData->key,
    ]);
})->with(LayoutEnum::cases());
