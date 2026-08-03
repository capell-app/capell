<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Layouts\Tables\LayoutsTable;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\Layouts\LayoutCardData;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('layout');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can list layouts', function (): void {
    $layouts = Layout::factory()->count(5)->create();

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->assertCanSeeTableRecords($layouts);
});

it('renders layouts as cards with expandable container metadata', function (): void {
    Storage::fake('public');

    $layout = Layout::factory()->createOne([
        'name' => 'Editorial layout',
        'key' => 'editorial-layout',
        'admin' => [
            'generated_preview_image' => 'generated-layout-previews/editorial-layout.png',
        ],
        'containers' => [
            'hero' => [
                'name' => 'Hero',
                'widgets' => [
                    ['widget_key' => 'hero-banner'],
                ],
            ],
            'main' => [
                'label' => 'Main Content',
                'widgets' => [
                    ['widget_key' => 'rich-text'],
                ],
            ],
        ],
        'updated_at' => now()->subMinutes(5),
    ]);

    Page::factory()->layout($layout)->create();

    $card = LayoutCardData::fromLayout($layout->refresh());

    expect($card->imageUrl)->toBe(Storage::disk('public')->url('generated-layout-previews/editorial-layout.png'))
        ->and($card->containerCount)->toBe(2)
        ->and($card->containerNames)->toBe(['Hero', 'Main Content']);

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertSee('Editorial layout')
        ->assertSee('editorial-layout')
        ->assertSee('generated-layout-previews/editorial-layout.png')
        ->assertSee('2')
        ->assertSee('Hero')
        ->assertSee('Main Content')
        ->assertSee(__('capell-admin::table.layout_containers'))
        ->assertSee(__('capell-admin::table.expand'))
        ->assertSee(__('capell-admin::table.last_updated'));
});

it('makes disabled and unused layouts obvious in the list', function (): void {
    Layout::factory()->createOne(['status' => false]);

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.disabled'))
        ->assertSee(__('capell-admin::table.layout_usage_unused'));
});

it('filters disabled and unused layouts from the selected variation-aware aggregate', function (): void {
    $disabledLayout = Layout::factory()->createOne(['status' => false]);
    $unusedLayout = Layout::factory()->createOne();
    $usedLayout = Layout::factory()->createOne();
    Page::factory()->layout($usedLayout)->createOne();

    $component = Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->filterTable('disabled')
        ->assertCanSeeTableRecords([$disabledLayout])
        ->assertCanNotSeeTableRecords([$unusedLayout, $usedLayout]);

    $component
        ->resetTableFilters()
        ->filterTable('unused')
        ->assertCanSeeTableRecords([$disabledLayout, $unusedLayout])
        ->assertCanNotSeeTableRecords([$usedLayout]);
});

it('hides the unused filter and its indicator from site-scoped actors', function (): void {
    $layout = Layout::factory()->createOne(['name' => 'Hidden usage layout']);
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();
    Page::factory()->site($hiddenSite)->layout($layout)->createOne();

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertTableFilterHidden('unused')
        ->set('tableFilters.unused.isActive', true)
        ->assertDontSee(__('capell-admin::table.unused'));
});

it('can search layouts', function (): void {
    $layouts = Layout::factory()
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Language(%d)', $sequence->index)])
        ->count(3)
        ->create();

    $name = $layouts->random()->name;

    $component = Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->searchTable($name)
        ->assertCountTableRecords(1)
        ->assertSee($name);

    $layouts->where('name', '!=', $name)
        ->each(fn (Layout $layout) => $component->assertDontSee($layout->name));
});

it('can sort layouts', function (): void {
    $layouts = Layout::factory()->count(5)->create();
    $sortedLayoutNames = $layouts->sortBy('name')->pluck('name')->all();

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->sortTable('name')
        ->assertSeeInOrder($sortedLayoutNames);
});

it('can replicate layout', function (): void {
    $layout = Layout::factory()->createOne();

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(
            TestAction::make(ReplicateAction::class)->table($layout),
            data: [
                'name' => $layout->name . ' (copy)',
                'key' => $layout->key . '-copy',
            ],
        )
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    assertDatabaseHas('layouts', [
        'name' => $layout->name . ' (copy)',
    ]);
});

it('groups related site and theme editing actions for layouts', function (): void {
    $site = Site::factory()->createOne();
    $theme = Theme::factory()->createOne(['name' => 'Layout theme']);
    $layout = Layout::factory()->site($site)->createOne(['theme_id' => $theme->getKey()]);

    $component = Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertTableActionHasUrl('edit-site', SiteResource::getUrl('edit', ['record' => $site]), record: $layout)
        ->mountTableAction('edit-theme', $layout)
        ->assertMountedActionModalSee($theme->name);

    $recordActions = $component->instance()->getTable()->getRecordActions();

    expect($recordActions[0]->getName())->toBe('edit')
        ->and($recordActions[1])->toBeInstanceOf(ActionGroup::class);

    assert($recordActions[1] instanceof ActionGroup);

    expect(array_keys($recordActions[1]->getFlatActions()))
        ->toContain('edit-site', 'edit-theme');
});

it('can delete layout', function (): void {
    $layout = Layout::factory()->createOne();

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($layout))
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(0);

    assertSoftDeleted($layout, ['id' => $layout->id]);
});

it('can group delete layouts', function (): void {
    $layouts = Layout::factory()->count(5)->create();

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->selectTableRecords($layouts)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    foreach ($layouts as $layout) {
        assertSoftDeleted($layout, ['id' => $layout->id]);
    }
});

it('page counts only include assigned sites for non-global users', function (): void {
    $layout = Layout::factory()->createOne();
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();

    Page::factory()->count(2)->site($assignedSite)->layout($layout)->create();
    Page::factory()->count(3)->site($hiddenSite)->layout($layout)->create();

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $method = new ReflectionMethod(LayoutsTable::class, 'getTableQueryModifier');

    $layoutWithCount = $method->invoke(null, Layout::query())
        ->whereKey($layout->getKey())
        ->firstOrFail();

    expect((int) $layoutWithCount->pages_count)->toBe(2);
});
