<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageResourceWidgetExtender;
use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Components\Forms\Page\ParentSelect;
use Capell\Admin\Filament\Components\Forms\Page\SiteSelect;
use Capell\Admin\Filament\Components\Forms\Page\TranslationsRepeater;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Pages\Widgets\ListPageAlertsWidget;
use Capell\Admin\Filament\Resources\Sites\Pages\CreateSite;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Tests\Fixtures\Autoload\TestDashboardFilamentWidgetForRegistrar;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Missing\Widget;

use function Pest\Laravel\get;

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 7) . '/tests/Support/DomQuery.php';

uses(CreatesAdminUser::class)
    ->group('page');

it('admin can see pages', function (): void {
    test()->actingAsAdmin();

    get(PageResource::getUrl())
        ->assertOk();
});

it('cannot see pages', function (): void {
    test()->actingAsUser();

    get(PageResource::getUrl())
        ->assertForbidden();
});

it('redirects creation to site setup until a site exists', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->default()->create();
    Blueprint::factory()->page()->default()->create();
    $createUrl = PageResource::getUrl('create');

    get($createUrl)->assertRedirectToRoute(CreateSite::getRouteName());

    Site::factory()
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->default()
        ->create();

    get($createUrl)->assertOk();
});

it('renders executable Alpine attributes for the translation tabs', function (): void {
    test()->actingAsAdmin();
    $language = Language::factory()->default()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($language)->withTranslations([$language, $french])->createOne();
    $page = Page::factory()->site($site)->withTranslations([$language, $french])->createOne();
    $response = get(PageResource::getUrl('edit', ['record' => $page]))->assertOk();
    $html = $response->getContent();
    throw_if(! is_string($html) || $html === '', RuntimeException::class, 'The page response was empty.');

    $element = domElement(domXPath($html), '//*[contains(@x-data, "capellRepeaterTabs")]');

    expect($element->getAttribute('x-data'))->toContain('data.activeTab')->not->toContain('@js(')
        ->and($element->getAttribute('x-init'))->toContain('$watch(')->not->toContain('&#039;')->not->toContain('&gt;')
        ->and($element->getAttribute('x-on:refresh-tabs.window'))->toContain('data.translations')->not->toContain('@js(');
});

it('applies page resource defaults and extension widgets for the admin surface', function (): void {
    test()->actingAsAdmin();
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->default()
        ->withTranslations($language)
        ->createOne();
    $layout = Layout::factory()->default()->createOne();
    $blueprint = Blueprint::factory()
        ->page()
        ->default()
        ->createOne(['group' => 'default']);

    app()->bind('tests.page-resource-widget-extender', fn (): PageResourceWidgetExtender => new class implements PageResourceWidgetExtender
    {
        public function getWidgets(): array
        {
            return [
                TestDashboardFilamentWidgetForRegistrar::class,
                stdClass::class,
                /** @phpstan-ignore class.notFound (A missing widget class is the invalid registration scenario under test.) */
                Widget::class,
            ];
        }
    });
    app()->tag('tests.page-resource-widget-extender', PageResourceWidgetExtender::TAG);

    $data = [
        'site_id' => '',
        'layout_id' => '',
        'blueprint_id' => '',
    ];

    PageResource::mutateFormDataBeforeCreate($data, [
        'translations' => [
            [
                'language_id' => $language->getKey(),
                'title' => 'Translated page title',
            ],
        ],
    ]);

    expect(PageResource::getPolicy())->toBe(PagePolicy::class)
        ->and(PageResource::getWidgets())->toContain(ListPageAlertsWidget::class, TestDashboardFilamentWidgetForRegistrar::class)
        ->not->toContain(stdClass::class)->not->toContain('Missing\\Widget')
        ->and($data)->toMatchArray([
            'site_id' => $site->getKey(),
            'layout_id' => $layout->getKey(),
            'blueprint_id' => $blueprint->getKey(),
            'name' => 'Translated page title',
        ]);
});

it('constrains page blueprints and builds global search details with breadcrumbs', function (): void {
    $systemBlueprint = Blueprint::factory()
        ->page()
        ->createOne(['group' => BlueprintGroupEnum::System->value, 'name' => 'System page']);
    $defaultBlueprint = Blueprint::factory()
        ->page()
        ->createOne(['group' => 'default', 'name' => 'Default page']);
    $marketingBlueprint = Blueprint::factory()
        ->page()
        ->createOne(['group' => 'marketing', 'name' => 'Marketing page']);
    $ungroupedBlueprint = Blueprint::factory()
        ->page()
        ->createOne(['group' => null, 'name' => 'Ungrouped page']);

    $allPagesQuery = Blueprint::query();
    PageResource::applyTypeAdminResourceConstraint($allPagesQuery, true);

    $withoutSystemQuery = Blueprint::query();
    PageResource::applyTypeAdminResourceConstraint($withoutSystemQuery);

    $systemOnlyQuery = Blueprint::query();
    PageResource::applyTypeAdminResourceConstraint($systemOnlyQuery, false);

    $site = Site::factory()->withTranslations()->createOne([
        'name' => 'Regional Site',
        'default' => false,
    ]);
    $parent = Page::factory()
        ->site($site)
        ->withTranslations()
        ->createOne(['name' => 'Parent section']);
    $page = Page::factory()
        ->site($site)
        ->parent($parent)
        ->withTranslations(data: ['title' => 'Translated search title'])
        ->createOne(['name' => 'Stored page name']);

    PageUrl::query()->where('pageable_id', $page->getKey())->update(['status' => false]);

    $details = array_map(
        filamentText(...),
        PageResource::getGlobalSearchResultDetails($page->load(['site', 'ancestors', 'translation'])),
    );

    expect($allPagesQuery->pluck('id')->all())->toContain(
        $systemBlueprint->getKey(),
        $defaultBlueprint->getKey(),
        $marketingBlueprint->getKey(),
        $ungroupedBlueprint->getKey(),
    )
        ->and($withoutSystemQuery->pluck('id')->all())->not->toContain($systemBlueprint->getKey())
        ->and($withoutSystemQuery->pluck('id')->all())->toContain($defaultBlueprint->getKey(), $marketingBlueprint->getKey(), $ungroupedBlueprint->getKey())
        ->and($systemOnlyQuery->pluck('id')->all())->toContain($systemBlueprint->getKey())
        ->and($systemOnlyQuery->pluck('id')->all())->not->toContain($defaultBlueprint->getKey())->not->toContain($marketingBlueprint->getKey())->not->toContain($ungroupedBlueprint->getKey())
        ->and($details[0] ?? null)->toBe('Translated search title')
        ->and($details[1] ?? null)->toContain('Regional Site', 'Parent section')
        ->and(implode(' ', $details))->toContain(__('capell-admin::table.page_availability_no_active_url'));
});

it('uses a site-compatible creation fallback without replacing explicit choices', function (): void {
    test()->actingAsAdmin();
    $siteA = Site::factory()->withTranslations()->createOne();
    $siteB = Site::factory()->withTranslations()->createOne();
    Layout::factory()->site($siteA)->createOne(['default' => true]);
    $layoutB = Layout::factory()->site($siteB)->createOne(['default' => false]);
    $type = Blueprint::factory()->page()->default()->createOne();
    $data = ['site_id' => $siteB->id, 'layout_id' => '', 'blueprint_id' => $type->id, 'name' => 'Internal title'];

    PageResource::mutateFormDataBeforeCreate($data);

    expect($data)->toMatchArray(['site_id' => $siteB->id, 'layout_id' => null, 'blueprint_id' => $type->id, 'name' => 'Internal title']);

    $layoutB->update(['default' => true]);
    $data['layout_id'] = '';
    PageResource::mutateFormDataBeforeCreate($data);
    expect($data['layout_id'])->toBe($layoutB->id);

    $shared = Layout::factory()->createOne(['site_id' => null, 'default' => false]);
    $data['layout_id'] = $shared->id;
    PageResource::mutateFormDataBeforeCreate($data);
    expect($data['layout_id'])->toBe($shared->id);
});

it('mounts editor quick create with layout defaults for the context site', function (string $defaultLayout): void {
    test()->actingAsAdmin();
    $language = Language::factory()->english()->createOne();
    $siteA = Site::factory()->language($language)->withTranslations($language)->createOne(['default' => true]);
    $siteB = Site::factory()->language($language)->withTranslations($language)->createOne(['default' => false]);
    Blueprint::factory()->page()->default()->createOne();
    Layout::factory()->site($siteA)->createOne(['default' => true]);
    $layoutB = Layout::factory()->site($siteB)->createOne(['default' => $defaultLayout === 'site']);
    $sharedLayout = $defaultLayout === 'shared'
        ? Layout::factory()->createOne(['site_id' => null, 'default' => true])
        : null;
    $page = Page::factory()->site($siteB)->layout($layoutB)->withTranslations($language)->createOne();

    $form = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    $action = TestAction::make(CreatePageAction::class);
    $form->mountAction($action)->assertActionMounted($action);
    $schemaName = expectPresent($form->instance()->getMountedActionSchemaName());

    $form->assertFormSet([
        'site_id' => $siteB->id,
        'layout_id' => $defaultLayout === 'site' ? $layoutB->id : $sharedLayout?->id,
    ], $schemaName);
})->with(['none', 'site', 'shared']);

it('quick creates from the list with the layout default of the context site', function (string $context, string $defaultLayout): void {
    test()->actingAsAdmin();
    $language = Language::factory()->english()->createOne();
    $siteA = Site::factory()->language($language)->withTranslations($language)->createOne(['default' => true]);
    $siteB = Site::factory()->language($language)->withTranslations($language)->createOne(['default' => false]);
    Blueprint::factory()->page()->default()->createOne();
    Layout::factory()->site($siteA)->createOne(['default' => true]);
    $layoutB = Layout::factory()->site($siteB)->createOne(['default' => $defaultLayout === 'site']);
    $sharedLayout = $defaultLayout === 'shared'
        ? Layout::factory()->createOne(['site_id' => null, 'default' => true])
        : null;

    $list = match ($context) {
        'list tab' => Livewire::test(ListPages::class)->set('activeTab', (string) $siteB->id),
        'list filter' => Livewire::test(ListPages::class)->set('activeTab', 'all')->filterTable('site_id', $siteB->id),
        default => throw new InvalidArgumentException('Unknown quick-create context: ' . $context),
    };

    $list->mountAction('create')
        ->assertSchemaStateSet(['site_id' => $siteB->id])
        ->fillForm(['title' => 'Context page'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $page = Page::query()->where('name', 'Context page')->sole();

    expect($page->site_id)->toBe($siteB->id)
        ->and($page->layout_id)->toBe($defaultLayout === 'site' ? $layoutB->id : $sharedLayout?->id);
})->with(['list tab', 'list filter'])->with(['site', 'shared']);

it('preserves unsupported content and clears stale choices when a creation form changes site', function (bool $quickCreate): void {
    test()->actingAsAdmin();
    $english = Language::factory()->createOne(['default' => true]);
    $french = Language::factory()->createOne(['default' => false, 'name' => "Lao People's Democratic Republic"]);
    $siteA = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $siteB = Site::factory()->language($english)->withTranslations($english)->createOne();
    Blueprint::factory()->page()->default()->createOne();
    $layoutA = Layout::factory()->site($siteA)->createOne();
    $parentA = Page::factory()->site($siteA)->layout($layoutA)->withTranslations([$english, $french])->createOne();
    $translations = [
        'english' => ['language_id' => $english->id, 'title' => 'Public title', 'content' => '<p>English draft</p>', 'meta' => ['slug' => 'manual-english'], 'slug_auto_update_disabled' => true],
        'french' => ['language_id' => $french->id, 'title' => 'Titre public', 'content' => '<p>Texte français</p>', 'meta' => ['slug' => 'url-francaise'], 'slug_auto_update_disabled' => true],
    ];
    // Raw UI state includes empty field bindings; reconciliation and persistence omit these placeholders.
    $hydratedTranslations = $translations;
    foreach ($hydratedTranslations as &$translation) {
        $translation['meta'] += array_fill_keys(['summary', 'label', 'link_text', 'title', 'description', 'keywords'], null);
    }

    unset($translation);

    $form = $quickCreate
        ? Livewire::test(EditPage::class, ['record' => $parentA->getRouteKey()])->mountAction(TestAction::make(CreatePageAction::class))
        : Livewire::test(CreatePage::class);
    $statePath = $quickCreate ? 'mountedActions.0.data' : 'data';
    $schemaName = $quickCreate ? $form->instance()->getMountedActionSchemaName() : 'form';
    $form->set($statePath . '.site_id', $siteA->id)
        ->set($statePath . '.translations', $translations)
        ->fillForm(['name' => 'Internal title', 'parent_id' => $parentA->id, 'layout_id' => $layoutA->id])
        ->set($statePath . '.site_id', $siteB->id)
        ->assertFormSet(['site_id' => $siteB->id, 'parent_id' => null, 'layout_id' => null], expectPresent($schemaName))
        ->assertNotified(__('capell-admin::message.page_site_choices_cleared'));

    $state = $form->get($statePath . '.translations');
    expect($state['english'])->toMatchArray($hydratedTranslations['english'])
        ->and($state['french'])->toMatchArray($hydratedTranslations['french']);

    $schema = $form->instance()->getSchema(expectPresent($schemaName));
    $siteSelect = expectPresent($schema)->getComponent('site_id');
    assert($siteSelect instanceof SiteSelect);
    expect(validator(['site_id' => $siteB->id], ['site_id' => $siteSelect->getValidationRules()])->fails())->toBeTrue();
    expect($siteSelect->getChildSchema(SiteSelect::BELOW_CONTENT_SCHEMA_KEY)?->toHtml())->toContain(e($french->name));

    $form->set($statePath . '.site_id', $siteA->id);
    $restored = $form->get($statePath . '.translations');
    expect($restored['french'])->toMatchArray($hydratedTranslations['french'])
        ->and($restored['english'])->toMatchArray($hydratedTranslations['english']);
})->with([false, true]);

it('requires an explicit language decision before saving the destination page', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->createOne(['default' => true]);
    $french = Language::factory()->createOne(['default' => false]);
    $siteA = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $siteB = Site::factory()->language($english)->withTranslations($english)->createOne();
    Blueprint::factory()->page()->default()->createOne();
    $layout = Layout::factory()->createOne(['site_id' => null]);
    $layoutA = Layout::factory()->site($siteA)->createOne();
    $parentA = Page::factory()->site($siteA)->layout($layoutA)->withTranslations([$english, $french])->createOne();
    $form = Livewire::test(CreatePage::class)
        ->set('data.site_id', $siteA->id)
        ->set('data.translations', [
            'english' => ['language_id' => $english->id, 'title' => 'Kept title', 'meta' => ['slug' => 'kept-title']],
            'french' => ['language_id' => $french->id, 'title' => 'Texte à conserver', 'content' => '<p>Draft</p>'],
        ])
        ->fillForm(['name' => 'Destination page', 'layout_id' => $layout->id])
        ->set('data.site_id', $siteB->id)
        ->call('create')
        ->assertHasFormErrors(['site_id']);

    expect(Page::query()->where('name', 'Destination page')->exists())->toBeFalse();
    $action = TestAction::make('useSiteLanguages')->schemaComponent('site_id', schema: 'form');
    $form->mountAction($action)->assertActionMounted($action);
    expect($form->get('data.translations.french.content'))->toBe('<p>Draft</p>');
    $form->callMountedAction();
    expect(collect($form->get('data.translations'))->pluck('language_id')->all())->toBe([$english->id]);
    $form->fillForm(['layout_id' => $layoutA->id, 'parent_id' => $parentA->id])
        ->call('create')->assertHasFormErrors(['layout_id', 'parent_id']);
    expect(Page::query()->where('name', 'Destination page')->exists())->toBeFalse();
    $form->fillForm(['layout_id' => $layout->id, 'parent_id' => null])
        ->call('create')->assertHasNoFormErrors();

    $page = Page::query()->where('name', 'Destination page')->sole();
    expect($page->site_id)->toBe($siteB->id)
        ->and($page->layout_id)->toBe($layout->id)
        ->and($page->translations()->sole()->title)->toBe('Kept title')
        ->and($page->translations()->sole()->meta)->toEqual(['slug' => 'kept-title']);
});

it('activates a retained translation after confirming language removal', function (bool $quickCreate, bool $retainActive): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne(['default' => true]);
    $french = Language::factory()->french()->createOne(['default' => false]);
    $german = Language::factory()->createOne(['name' => 'German', 'code' => 'de', 'default' => false]);
    $siteA = Site::factory()->language($english)->withTranslations([$english, $french, $german])->createOne();
    $siteB = Site::factory()->language($english)->withTranslations($retainActive ? [$english, $german] : [$english])->createOne();
    Blueprint::factory()->page()->default()->createOne();
    if ($quickCreate) {
        $page = Page::factory()->site($siteA)->withTranslations([$english, $french, $german])->createOne();
        $form = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->mountAction(TestAction::make(CreatePageAction::class));
    } else {
        $form = Livewire::test(CreatePage::class);
    }

    $statePath = $quickCreate ? 'mountedActions.0.data' : 'data';
    $schemaName = $quickCreate ? expectPresent($form->instance()->getMountedActionSchemaName()) : 'form';
    $outerSiteId = $form->get('data.site_id');
    $outerActiveTab = $form->get('data.activeTab');
    $outerData = $form->get('data');
    $form->set($statePath . '.site_id', $siteA->id)
        ->set($statePath . '.translations', [
            'english' => ['language_id' => $english->id, 'title' => 'Retained title', 'content' => '<p>English draft</p>'],
            'french' => ['language_id' => $french->id, 'title' => 'Titre français'],
            'german' => ['language_id' => $german->id, 'title' => 'Deutscher Titel', 'content' => '<p>German draft</p>'],
        ]);

    // Both rendered handlers select the same tab through the Livewire state path shared by $wire.$entangle().
    view()->share('errors', new ViewErrorBag);
    $form->assertSet($statePath . '.activeTab', 1);
    $schema = expectPresent($form->instance()->getSchema($schemaName));
    $repeater = $schema->getComponentByStatePath($statePath . '.translations', withHidden: true, withAbsoluteStatePath: true);
    assert($repeater instanceof TranslationsRepeater);
    $document = domXPath($repeater->toHtml());
    $position = $retainActive ? 2 : 1;
    $tab = domElement($document, sprintf('(//*[@role="tab"])[%d]', $position + 1));
    $panel = domElement($document, sprintf('(//*[@role="tabpanel"])[%d]', $position + 1));
    $process = new Process(['node', '--input-type=module', '-e', <<<'JS_WRAP'
    import { readFileSync } from 'node:fs';
    import capellRepeaterTabs from './packages/admin/resources/js/components/repeater-tabs.js';
    const { handlers, activeTab, panelCount, panelPosition } = JSON.parse(readFileSync(0, 'utf8'));
    // Alpine supplies $el: the panel that fired the event, among its sibling panels.
    const panels = Array.from({ length: panelCount }, () => ({
        matches: () => true,
        get parentElement() {
            return { children: panels };
        },
    }));
    const selections = {};
    for (const [event, handler] of Object.entries(handlers)) {
        const state = capellRepeaterTabs({ activeTab });
        new Function('$el', `with (this) { ${handler} }`).call(state, panels[panelPosition]);
        selections[event] = { tab: state.tab, focusedTab: state.focusedTab };
    }
    process.stdout.write(JSON.stringify(selections));
    JS_WRAP]);
    $process->setInput(json_encode([
        'handlers' => [
            'tab click' => $tab->getAttribute('x-on:click.stop'),
            'panel expansion' => $panel->getAttribute('x-on:expand'),
        ],
        'activeTab' => $repeater->getActiveTab(),
        'panelCount' => domCount($document, '//*[@role="tabpanel"]'),
        'panelPosition' => $position,
    ], JSON_THROW_ON_ERROR));
    $process->mustRun();

    $selections = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $activeTab = $position + 1;
    $activeTabStatePath = $repeater->getActiveTabStatePath();
    expect($selections)->toBe([
        'tab click' => ['tab' => $activeTab, 'focusedTab' => $activeTab],
        'panel expansion' => ['tab' => $activeTab, 'focusedTab' => $activeTab],
    ])->and($activeTabStatePath)->toBe($statePath . '.activeTab');
    $form->set($activeTabStatePath, $selections['tab click']['tab'])->assertSet($activeTabStatePath, $activeTab);

    $form->set($statePath . '.site_id', $siteB->id);
    $action = TestAction::make('useSiteLanguages')->schemaComponent('site_id', schema: $schemaName);
    $form->mountAction($action)->assertActionMounted($action);
    expect($form->get($statePath . '.translations.french.title'))->toBe('Titre français');
    $form->callMountedAction()
        ->assertSet($statePath . '.activeTab', $retainActive ? 2 : 1)
        ->assertDispatched('refresh-tabs', tabId: $retainActive ? 2 : 1, statePath: $statePath . '.translations');

    expect(array_keys($form->get($statePath . '.translations')))->toBe($retainActive ? ['english', 'german'] : ['english']);
    $form->set($statePath . '.translations.english.title', 'Edited retained title')
        ->assertSet($statePath . '.translations.english.content', '<p>English draft</p>')
        ->assertSet($statePath . '.translations.english.title', 'Edited retained title');
    if ($retainActive) {
        $form->set($statePath . '.translations.german.title', 'Bearbeiteter Titel')
            ->assertSet($statePath . '.translations.german.title', 'Bearbeiteter Titel')
            ->assertSet($statePath . '.translations.german.content', '<p>German draft</p>')
            ->assertSet($statePath . '.activeTab', 2);
    }

    if ($quickCreate) {
        expect($form->get('data.site_id'))->toBe($outerSiteId)
            ->and($form->get('data.activeTab'))->toBe($outerActiveTab)
            ->and($form->get('data'))->toBe($outerData);
    }
})->with(['ordinary creation' => false, 'quick create' => true])
    ->with(['removed active row' => false, 'retained active row moves' => true]);

it('keeps compatible content and eligible selections outside the first parent options', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->createOne();
    $siteA = Site::factory()->language($english)->withTranslations($english)->createOne();
    $siteB = Site::factory()->language($english)->createOne();
    $type = Blueprint::factory()->page()->default()->createOne();
    $shared = Layout::factory()->createOne(['site_id' => null]);
    Page::factory()->count(11)->site($siteB)->for($type, 'blueprint')->withTranslations($english)->create();
    $parent = Page::factory()->site($siteB)->for($type, 'blueprint')->withTranslations($english)->createOne();
    $form = Livewire::test(CreatePage::class)
        ->set('data.site_id', $siteA->id)
        ->set('data.translations', ['english' => ['language_id' => $english->id, 'title' => 'Public title', 'meta' => ['slug' => 'manual-url']]])
        ->fillForm(['name' => 'Internal reference', 'layout_id' => $shared->id, 'parent_id' => $parent->id])
        ->set('data.site_id', $siteB->id)
        ->assertFormSet(['parent_id' => $parent->id, 'layout_id' => $shared->id]);

    expect($form->get('data.translations.english.title'))->toBe('Public title')
        ->and($form->get('data.translations.english.meta.slug'))->toBe('manual-url');

    $schema = $form->instance()->getSchema('form');
    $select = expectPresent($schema)->getComponent('parent_id');
    assert($select instanceof ParentSelect);
    expect($select->getOptions())->not->toHaveKey($parent->id);
    expect($select->isOptionEligible($parent->id))->toBeTrue();
    expect($select->getOptions())->not->toHaveKey($parent->id);
});
