<?php

declare(strict_types=1);

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertNotSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('content-graph');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('blocks delete when strong graph dependencies exist', function (): void {
    $layout = Layout::factory()->createOne();
    $page = Page::factory()->createOne(['layout_id' => $layout->id]);

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
    ]);

    $result = ValidateContentDeleteImpactAction::run($layout);

    expect($result->allowed)->toBeFalse()
        ->and($result->blockingCount)->toBe(1);
});

it('allows delete when only weak graph dependencies exist', function (): void {
    $layout = Layout::factory()->createOne();
    $page = Page::factory()->createOne();

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => ContentGraphEdgeKind::DescribesPage,
        'strength' => ContentGraphEdgeStrength::Weak,
        'source_package' => 'capell-app/core',
    ]);

    $result = ValidateContentDeleteImpactAction::run($layout);

    expect($result->allowed)->toBeTrue()
        ->and($result->warningCount)->toBe(1);
});

it('blocks page list row delete when strong graph dependencies exist', function (): void {
    $page = Page::factory()->createOne();
    $dependentPage = Page::factory()->createOne();

    createContentGraphEdge($dependentPage, $page, ContentGraphEdgeStrength::Strong);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->callAction(TestAction::make(DeleteAction::class)->table($page))
        ->assertNotified(__('capell-admin::message.content_graph_delete_blocked'));

    assertNotSoftDeleted($page, ['id' => $page->id]);
});

it('blocks page list bulk delete when strong graph dependencies exist', function (): void {
    $page = Page::factory()->createOne();
    $dependentPage = Page::factory()->createOne();

    createContentGraphEdge($dependentPage, $page, ContentGraphEdgeStrength::Strong);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$page])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified(__('capell-admin::message.content_graph_delete_blocked'));

    assertNotSoftDeleted($page, ['id' => $page->id]);
});

it('blocks edit page delete when strong graph dependencies exist', function (): void {
    $page = Page::factory()->createOne();
    $dependentPage = Page::factory()->createOne();

    createContentGraphEdge($dependentPage, $page, ContentGraphEdgeStrength::Strong);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction(DeleteAction::class)
        ->assertNotified(__('capell-admin::message.content_graph_delete_blocked'));

    assertNotSoftDeleted($page, ['id' => $page->id]);
});

it('allows page list row delete when only weak graph dependencies exist', function (): void {
    $page = Page::factory()->createOne();
    $dependentPage = Page::factory()->createOne();

    createContentGraphEdge($dependentPage, $page, ContentGraphEdgeStrength::Weak);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->callAction(TestAction::make(DeleteAction::class)->table($page))
        ->assertHasNoFormErrors();

    expect($page->fresh()?->trashed())->toBeTrue();
});

it('blocks layout list row delete when strong graph dependencies exist', function (): void {
    $layout = Layout::factory()->createOne();
    $page = Page::factory()->createOne();

    createContentGraphEdge($page, $layout, ContentGraphEdgeStrength::Strong);

    Livewire::test(ListLayouts::class)
        ->assertSuccessful()
        ->callAction(TestAction::make(DeleteAction::class)->table($layout))
        ->assertNotified(__('capell-admin::message.content_graph_delete_blocked'));

    assertNotSoftDeleted($layout, ['id' => $layout->id]);
});

function createContentGraphEdge(Page $source, Page|Layout $target, ContentGraphEdgeStrength $strength): ContentGraphEdge
{
    return ContentGraphEdge::query()->create([
        'source_type' => $source::class,
        'source_id' => $source->id,
        'target_type' => $target::class,
        'target_id' => $target->id,
        'kind' => $target instanceof Layout
            ? ContentGraphEdgeKind::UsesLayout
            : ContentGraphEdgeKind::CanonicalizesTo,
        'strength' => $strength,
        'source_package' => 'capell-app/core',
    ]);
}
