<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageResourceWidgetExtender;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Widgets\ListPageAlertsWidget;
use Capell\Admin\Filament\Resources\Sites\Pages\CreateSite;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Tests\Fixtures\Autoload\TestDashboardFilamentWidgetForRegistrar;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Missing\Widget;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('page');

test('admin can see pages', function (): void {
    test()->actingAsAdmin();

    get(PageResource::getUrl())
        ->assertOk();
});

test('cannot see pages', function (): void {
    test()->actingAsUser();

    get(PageResource::getUrl())
        ->assertForbidden();
});

test('admin cannot create page without a site', function (): void {
    test()->actingAsAdmin();

    Language::factory()->default()->create();
    Blueprint::factory()->page()->default()->create();

    get(PageResource::getUrl('create'))
        ->assertRedirectToRoute(CreateSite::getRouteName());
});

test('admin can see create page', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->default()->create();
    Blueprint::factory()->page()->default()->create();

    Site::factory()
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->default()
        ->create();

    get(PageResource::getUrl('create'))
        ->assertOk();
});

test('admin can see edit page', function (): void {
    test()->actingAsAdmin();

    get(PageResource::getUrl('edit', ['record' => Page::factory()->createOne()]))
        ->assertOk();
});

it('applies page resource defaults and extension widgets for the admin surface', function (): void {
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
        ->not->toContain(stdClass::class, 'Missing\\Widget')
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
        ->and($systemOnlyQuery->pluck('id')->all())->not->toContain($defaultBlueprint->getKey(), $marketingBlueprint->getKey(), $ungroupedBlueprint->getKey())
        ->and($details[0] ?? null)->toBe('Translated search title')
        ->and($details[1] ?? null)->toContain('Regional Site', 'Parent section');
});
