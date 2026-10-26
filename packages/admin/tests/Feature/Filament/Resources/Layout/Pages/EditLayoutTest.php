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
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('layout');

beforeEach(function (): void {
    test()->actingAsAdmin();
    resolve(LayoutViewRegistry::class)->registerMaster('page.updated', 'Updated page');
    resolve(LayoutViewRegistry::class)->registerLayout('layout.updated', 'Updated layout');
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

it('validates edit layout', function (): void {
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

it('can not delete layout if it is used', function (): void {
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

it('can edit layouts', function (LayoutEnum $layoutEnum): void {
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

it('offers registered labelled wrapper and body views in their own selectors', function (): void {
    resolve(LayoutViewRegistry::class)->registerMaster('page.alternative', 'Alternative wrapper');
    resolve(LayoutViewRegistry::class)->registerLayout('layout.alternative', 'Alternative body');

    $layout = Layout::factory()->createOne();

    Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
        ->assertFormFieldExists('meta.master_file', fn (Field $field): bool => $field instanceof Select
                && ($field->getOptions()['page.updated'] ?? null) === 'Updated page'
            && ($field->getOptions()['page.alternative'] ?? null) === 'Alternative wrapper'
                && ! array_key_exists('layout.updated', $field->getOptions()))
        ->assertFormFieldExists('meta.layout_file', fn (Field $field): bool => $field instanceof Select
                && ($field->getOptions()['layout.updated'] ?? null) === 'Updated layout'
            && ($field->getOptions()['layout.alternative'] ?? null) === 'Alternative body'
                && ! array_key_exists('page.updated', $field->getOptions()));
});

it('explains unavailable saved views and rejects forged view selections', function (): void {
    $layout = Layout::factory()->createOne();
    // Simulate a saved layout whose consumer has since removed its registration.
    $layout->forceFill(['meta' => ['master_file' => 'removed.wrapper']])->saveQuietly();

    Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
        ->assertSee(__('capell-admin::layout_views.unavailable', ['view' => 'removed.wrapper']))
        ->fillForm(['meta' => ['master_file' => 'unregistered.wrapper', 'layout_file' => 'unregistered.body']])
        ->call('save')
        ->assertHasFormErrors(['meta.master_file', 'meta.layout_file']);

    expect($layout->refresh()->meta['master_file'])->toBe('removed.wrapper');
});

it('lets editors clear registered view overrides to retain default rendering', function (): void {
    $layout = Layout::factory()->createOne(['meta' => ['master_file' => 'page.updated', 'layout_file' => 'layout.updated']]);

    Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
        ->fillForm(['meta' => ['master_file' => null, 'layout_file' => null]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($layout->refresh()->meta['master_file'] ?? null)->toBeNull()
        ->and($layout->meta['layout_file'] ?? null)->toBeNull();
});

it('explains registration when a consumer has no registered view choices', function (): void {
    app()->instance(LayoutViewRegistry::class, new LayoutViewRegistry);
    $layout = Layout::factory()->createOne();

    Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
        ->assertSee(__('capell-admin::layout_views.registration'))
        ->assertFormFieldExists('meta.master_file', fn (Select $field): bool => $field->getOptions() === [])
        ->assertFormFieldExists('meta.layout_file', fn (Select $field): bool => $field->getOptions() === []);
});
