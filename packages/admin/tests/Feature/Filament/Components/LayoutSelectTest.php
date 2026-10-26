<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Page\LayoutSelect;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Tests\Fixtures\Livewire as FormLivewire;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(CreatesAdminUser::class);

it('layout select search ordering binds user supplied search text', function (): void {
    $component = LayoutSelect::make('layout_id');
    $query = Layout::query();
    $search = "Hero' THEN 1 ELSE 1 END; DROP TABLE layouts; --";

    $method = new ReflectionMethod($component, 'applySearchOrdering');
    $method->invoke($component, $query, $search);

    $bindings = $query->getQuery()->getRawBindings();
    $orderBindings = $bindings['order'];

    expect($query)
        ->toBeInstanceOf(Builder::class)
        ->and($query->toSql())->not->toContain($search)
        ->and($orderBindings)->toContain(mb_strtolower($search));
});

it('layout select does not preload every layout option into page edit payloads', function (): void {
    $component = LayoutSelect::make('layout_id');

    expect($component->isPreloaded())->toBeFalse();
});

it('layout select falls back to generated preview image metadata', function (): void {
    Storage::fake('public');

    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne([
        'admin' => [
            'generated_preview_image' => 'generated-layout-previews/layout-preview.png',
        ],
    ]);

    expect($component->getOptionLabelFromRecord($layout))
        ->toContain('generated-layout-previews/layout-preview.png');
});

it('layout select keeps thumbnail in selected option without rendering preview below field', function (): void {
    Storage::fake('public');

    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne([
        'admin' => [
            'generated_preview_image' => 'generated-layout-previews/layout-preview.png',
        ],
    ]);

    expect($component->getOptionLabelFromRecord($layout))
        ->toContain('generated-layout-previews/layout-preview.png')
        ->and(layoutSelectChildComponents($component))
        ->toBe([]);
});

it('marks disabled and unused layouts in select options', function (): void {
    test()->actingAsAdmin();

    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne(['status' => false]);
    $layout->setAttribute('pages_count', 0);

    expect($component->getOptionLabelFromRecord($layout))
        ->toContain(__('capell-admin::form.disabled'))
        ->toContain(__('capell-admin::table.layout_usage_unused'));
});

it('keeps the actor-visible usage count without marking it as globally authoritative', function (): void {
    $layout = Layout::factory()->createOne(['status' => true]);
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();
    Page::factory()->site($assignedSite)->layout($layout)->createOne();
    Page::factory()->count(2)->site($hiddenSite)->layout($layout)->create();

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $component = LayoutSelect::make('layout_id');
    mountLayoutSelectForSiteTest($component);
    $property = new ReflectionProperty(Select::class, 'modifyRelationshipQueryUsing');
    $modifyQuery = $property->getValue($component);
    assert($modifyQuery instanceof Closure);

    $layout = $component->evaluate($modifyQuery, ['query' => Layout::query(), 'search' => null])
        ->whereKey($layout->getKey())
        ->sole();

    $option = $component->getOptionLabelFromRecord($layout);

    expect($option)
        ->toContain('Pages: 1')
        ->not->toContain(__('capell-admin::table.layout_usage_unused'));
});

it('does not run a fallback usage query while rendering an option without the selected aggregate', function (): void {
    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne();

    DB::enableQueryLog();
    $component->getOptionLabelFromRecord($layout);

    expect(collect(DB::getQueryLog())->pluck('query')->implode(' '))
        ->not->toContain('from "pages"');
});

it('loads a single cross-variation usage aggregate for relationship options', function (): void {
    $component = LayoutSelect::make('layout_id');
    mountLayoutSelectForSiteTest($component);
    $property = new ReflectionProperty(Select::class, 'modifyRelationshipQueryUsing');
    $modifyQuery = $property->getValue($component);

    expect($modifyQuery)->toBeInstanceOf(Closure::class);
    assert($modifyQuery instanceof Closure);

    $query = $component->evaluate($modifyQuery, ['query' => Layout::query(), 'search' => null]);

    expect($query->toSql())->toContain('pages_count');
});

/**
 * @return array<string, mixed>
 */
function layoutSelectChildComponents(LayoutSelect $component): array
{
    Schema::make(new CreatePage)
        ->model(Page::class)->components([$component])->getComponents();

    return $component->getChildSchema(LayoutSelect::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [];
}

/** @param array<string, mixed> $state */
function mountLayoutSelectForSiteTest(LayoutSelect $component, array $state = [], ?Page $record = null, string $operation = 'create'): void
{
    Schema::make(FormLivewire::make()->data($state))
        ->model($record ?? Page::class)
        ->operation($operation)
        ->statePath('data')
        ->components([$component])
        ->getComponents();
}

it('offers only selected-site and shared layouts in options and search', function (string $operation): void {
    $siteA = Site::factory()->createOne();
    $siteB = Site::factory()->createOne();
    $siteC = Site::factory()->createOne();
    $layoutA = Layout::factory()->site($siteA)->createOne(['name' => 'Candidate A']);
    $layoutB = Layout::factory()->site($siteB)->createOne(['name' => 'Candidate B']);
    $layoutC = Layout::factory()->site($siteC)->createOne(['name' => 'Candidate C']);
    $shared = Layout::factory()->createOne(['name' => 'Candidate shared', 'site_id' => null]);
    test()->actingAs(ScopedAdminUser::make(collect([$siteA->id, $siteB->id])));
    $component = LayoutSelect::make('layout_id');
    mountLayoutSelectForSiteTest($component, ['site_id' => $siteB->id], operation: $operation);

    expect(validator(['layout_id' => $layoutA->id], ['layout_id' => $component->getValidationRules()])->fails())->toBeTrue();

    foreach ([$component->getSearchResults(''), $component->getSearchResults('Candidate')] as $options) {
        expect($options)->toHaveKeys([$layoutB->id, $shared->id])
            ->not->toHaveKey($layoutA->id)
            ->not->toHaveKey($layoutC->id);
    }
})->with(['create', 'createOption']);

it('uses the edited page site when a configurator omits the site field', function (): void {
    test()->actingAsAdmin();
    $siteA = Site::factory()->createOne();
    $siteB = Site::factory()->createOne();
    $layoutA = Layout::factory()->site($siteA)->createOne();
    $layoutB = Layout::factory()->site($siteB)->createOne();
    $page = Page::factory()->site($siteB)->layout($layoutB)->createOne();
    $component = LayoutSelect::make('layout_id');
    mountLayoutSelectForSiteTest($component, record: $page, operation: 'edit');

    expect($component->getSearchResults(''))->toHaveKey($layoutB->id)->not->toHaveKey($layoutA->id);
    expect(validator(['layout_id' => $layoutB->id], ['layout_id' => $component->getValidationRules()])->passes())->toBeTrue();
});

it('chooses an eligible default and honours configurator restrictions', function (bool $shared): void {
    test()->actingAsAdmin();
    $siteA = Site::factory()->createOne();
    $siteB = Site::factory()->createOne();
    Layout::factory()->site($siteA)->createOne(['default' => true]);
    $layout = Layout::factory()->createOne(['site_id' => $shared ? null : $siteB->id, 'default' => false]);
    $hidden = Layout::factory()->site($siteB)->createOne(['default' => false, 'admin' => ['hidden_from_selection' => true]]);
    $component = LayoutSelect::make('layout_id');
    mountLayoutSelectForSiteTest($component, ['site_id' => $siteB->id]);

    expect($component->getDefaultState())->toBeNull();
    $layout->update(['default' => true]);
    $hidden->update(['default' => true]);

    expect($component->getDefaultState())->toBe($layout->id)
        ->and($component->getSearchResults(''))->not->toHaveKey($hidden->id);

    $component->modifyQueryUsing(function (Builder $query) use ($hidden): void {
        $query->whereKey($hidden->id);
    });
    expect($component->getDefaultState())->toBeNull()
        ->and($component->getSearchResults(''))->toBe([]);
})->with([true, false]);

it('keeps shared layout choices when a configurator supplies no site context', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $siteLayout = Layout::factory()->site($site)->createOne(['default' => true]);
    $shared = Layout::factory()->createOne(['site_id' => null, 'default' => false]);
    $component = LayoutSelect::make('layout_id');
    mountLayoutSelectForSiteTest($component, operation: 'createOption');

    expect($component->getSearchResults(''))->toHaveKey($shared->id)->not->toHaveKey($siteLayout->id);
    expect($component->getDefaultState())->toBeNull();
    $shared->update(['default' => true]);
    expect($component->getDefaultState())->toBe($shared->id);
});
