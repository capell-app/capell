<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Filament\Components\Forms\PageSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures\PageSelectFilteringExtenderForTest;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('builds page options scoped by site type and search text', function (): void {
    $site = Site::factory()->withTranslations()->create(['name' => 'Primary']);
    $otherSite = Site::factory()->withTranslations()->create(['name' => 'Other']);
    $articleType = Blueprint::factory()->page()->create(['key' => 'article', 'name' => 'Article']);
    $landingType = Blueprint::factory()->page()->create(['key' => 'landing', 'name' => 'Landing']);
    $systemType = Blueprint::factory()->page()->group(BlueprintGroupEnum::System->value)->create(['key' => 'system']);

    $parent = Page::factory()->site($site)->type($landingType)->create(['name' => 'Knowledge']);
    $article = Page::factory()->site($site)->type($articleType)->parent($parent)->create(['name' => 'Knowledge Base']);
    Page::factory()->site($site)->type($landingType)->create(['name' => 'Landing Page']);
    Page::factory()->site($otherSite)->type($articleType)->create(['name' => 'Knowledge Other']);
    Page::factory()->site($site)->type($systemType)->create(['name' => 'Knowledge System']);

    $options = pageSelectOptions(
        PageSelect::make('page_id')->pageType('article'),
        siteId: $site->getKey(),
        search: 'Knowledge',
    );

    expect($options)->toHaveKey($article->getKey())
        ->and($options[$article->getKey()])->toContain('Knowledge Base')
        ->and($options)->not->toContain('Knowledge Other')
        ->and($options)->not->toContain('Knowledge System');
});

it('does not preload page options into initial admin form payloads', function (): void {
    $component = PageSelect::make('page_id');

    expect($component->isPreloaded())->toBeFalse();
});

it('filters page options by admin resource group', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $pageType = Blueprint::factory()->page()->create(['key' => 'standard', 'group' => null]);
    $articleType = Blueprint::factory()->page()->create(['key' => 'article', 'group' => 'article']);

    $page = Page::factory()->site($site)->type($pageType)->create(['name' => 'Visible page']);
    Page::factory()->site($site)->type($articleType)->create(['name' => 'Article page']);

    $options = pageSelectOptions(
        PageSelect::make('page_id')->pageGroup('page'),
        siteId: $site->getKey(),
        search: 'page',
    );

    expect($options)->toHaveKey($page->getKey())
        ->and(implode(' ', $options))->not->toContain('Article page');
});

it('applies parent page type and custom query constraints to page options', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $articleType = Blueprint::factory()->page()->create(['key' => 'article']);
    $sectionType = Blueprint::factory()->page()->create(['key' => 'section']);
    $otherParentType = Blueprint::factory()->page()->create(['key' => 'other-section']);

    $section = Page::factory()->site($site)->type($sectionType)->create(['name' => 'Docs']);
    $allowedChild = Page::factory()->site($site)->type($articleType)->parent($section)->create(['name' => 'Published child']);
    $draftChild = Page::factory()->site($site)->type($articleType)->parent($section)->create(['name' => 'Draft child']);
    $otherParent = Page::factory()->site($site)->type($otherParentType)->create(['name' => 'Other']);
    Page::factory()->site($site)->type($articleType)->parent($otherParent)->create(['name' => 'Wrong parent child']);

    $options = pageSelectOptions(
        PageSelect::make('page_id')
            ->pageType('article')
            ->parentPageType('section')
            ->modifySelectOptionsQueryUsing(fn (Builder $query): Builder => $query->whereKeyNot($draftChild->getKey())),
        siteId: $site->getKey(),
    );

    expect($options)->toHaveKey($allowedChild->getKey())
        ->and($options)->not->toHaveKey($draftChild->getKey())
        ->and(implode(' ', $options))->not->toContain('Wrong parent child');
});

it('adds a disabled more-results option when the available page count exceeds the options limit', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $type = Blueprint::factory()->page()->create(['key' => 'article']);

    Page::factory()->count(3)->site($site)->type($type)->sequence(
        ['name' => 'Alpha'],
        ['name' => 'Beta'],
        ['name' => 'Gamma'],
    )->create();

    $component = PageSelect::make('page_id')
        ->pageType('article')
        ->optionsLimit(2);

    $options = pageSelectOptions($component, siteId: $site->getKey());

    expect($options)->toHaveCount(3)
        ->and($options)->toHaveKey('')
        ->and($options[''])->toBe(__('capell-admin::form.more_results', ['count' => 1]));
});

it('applies tagged page table extenders when building admin page options', function (): void {
    app()->bind(PageSelectFilteringExtenderForTest::class);
    app()->tag([PageSelectFilteringExtenderForTest::class], PageTableExtender::TAG);

    $site = Site::factory()->withTranslations()->create();
    $type = Blueprint::factory()->page()->create(['key' => 'article']);

    $visiblePage = Page::factory()->site($site)->type($type)->create(['name' => 'Visible page']);
    Page::factory()->site($site)->type($type)->create(['name' => 'Hidden page']);

    $options = pageSelectOptions(
        PageSelect::make('page_id')->pageType('article'),
        siteId: $site->getKey(),
    );

    expect($options)->toHaveKey($visiblePage->getKey())
        ->and(implode(' ', $options))->not->toContain('Hidden page');
});

it('configures create and edit option actions for nested page management', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $type = Blueprint::factory()->page()->create(['key' => 'article', 'name' => 'Article']);
    $layout = Layout::factory()->create();
    $page = Page::factory()->site($site)->type($type)->create(['name' => 'Knowledge Base']);

    $pageSelect = PageSelect::make('page_id');
    $pageSelect->withCreateForm();
    $pageSelect->withEditForm();

    $component = mountedPageSelect($pageSelect, ['page_id' => $page->getKey()]);

    $createAction = $component->getCreateOptionAction();
    $editAction = $component->getEditOptionAction();

    expect($createAction)->toBeInstanceOf(Action::class)
        ->and($editAction)->toBeInstanceOf(Action::class)
        ->and($component->getOptionLabelFromRecord($page))->toContain('Knowledge Base')
        ->and($component->getEditOptionActionFormData())->toMatchArray([
            'id' => $page->getKey(),
            'name' => 'Knowledge Base',
        ]);

    $createdKey = $component->evaluate($component->getCreateOptionUsing(), [
        'component' => $component,
        'data' => [
            'name' => 'Nested child',
            'blueprint_id' => $type->getKey(),
            'layout_id' => $layout->getKey(),
            'site_id' => $site->getKey(),
        ],
    ]);

    expect(Page::query()->find($createdKey)?->name)->toBe('Nested child');

    $configuredEditAction = configuredSelectAction($component, 'modifyEditOptionActionUsing', 'editOption');

    $editHeading = evaluateActionProperty(
        $configuredEditAction,
        'modalHeading',
        [
            'context' => 'editOption',
            'state' => $page->getKey(),
        ],
        [
            PageSelect::class => $component,
        ],
    );

    expect((string) $editHeading)->toBe('Edit page');

    $configuredEditAction->callAfter();

    $component->state(null);

    expect($component->getEditOptionActionForm(Schema::make(Livewire::make())))->toBeNull();
});

it('only exposes the hint edit link for persisted selected pages', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $type = Blueprint::factory()->page()->create(['key' => 'article']);
    $page = Page::factory()->site($site)->type($type)->create(['name' => 'Editable page']);

    $component = mountedPageSelect(
        PageSelect::make('page_id')->withHintEditAction(),
        ['page_id' => $page->getKey()],
    );

    $hintAction = collect($component->getHintActions())->first();

    throw_unless($hintAction instanceof Action, RuntimeException::class, 'Expected PageSelect hint edit action.');

    expect(evaluateActionProperty($hintAction, 'isVisible', [
        'operation' => 'edit',
        'state' => $page->getKey(),
    ]))->toBeTrue()
        ->and(evaluateActionProperty($hintAction, 'isVisible', [
            'operation' => 'create',
            'state' => $page->getKey(),
        ]))->toBeFalse()
        ->and(evaluateActionProperty($hintAction, 'url', [
            'state' => $page->getKey(),
        ]))->toContain((string) $page->getKey())
        ->and(evaluateActionProperty($hintAction, 'url', [
            'state' => null,
        ]))->toBeNull()
        ->and(evaluateActionProperty($hintAction, 'url', [
            'state' => 999999,
        ]))->toBeNull();
});

/**
 * @return array<int|string|null, string>
 */
function pageSelectOptions(PageSelect $component, ?int $siteId = null, ?string $search = null): array
{
    $callback = Closure::bind(
        fn (): array => $component->getPageOptions(site_id: $siteId, search: $search),
        null,
        PageSelect::class,
    );

    return $callback();
}

/**
 * @param  array<string, mixed>  $state
 */
function mountedPageSelect(PageSelect $component, array $state = []): PageSelect
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->operation('edit')
        ->components([$component]);

    $mounted = $schema->getComponents()[0];

    throw_unless($mounted instanceof PageSelect, RuntimeException::class, 'Expected mounted PageSelect component.');

    $mounted->state(data_get($state, $mounted->getName()));

    return $mounted;
}

/**
 * @param  array<string, mixed>  $namedInjections
 * @param  array<class-string, mixed>  $typedInjections
 */
function evaluateActionProperty(Action $action, string $property, array $namedInjections = [], array $typedInjections = []): mixed
{
    $reflectionProperty = new ReflectionProperty($action, $property);

    return $action->evaluate($reflectionProperty->getValue($action), $namedInjections, $typedInjections);
}

function configuredSelectAction(PageSelect $component, string $callbackProperty, string $actionName): Action
{
    $reflectionProperty = new ReflectionProperty($component, $callbackProperty);
    $callback = $reflectionProperty->getValue($component);

    throw_unless($callback instanceof Closure, RuntimeException::class, 'Expected select action configuration callback.');

    $action = Action::make($actionName)->schemaComponent($component);

    $configuredAction = $component->evaluate($callback, ['action' => $action]);

    throw_unless($configuredAction instanceof Action, RuntimeException::class, 'Expected configured select action.');

    return $configuredAction;
}
