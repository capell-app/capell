<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SiteCreateWizardHookEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\Site\Tab\DetailsTab;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\ChildrenRelationManager;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\SiblingsRelationManager;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Schemas\AbstractPageSchemaExtender;
use Capell\Admin\Support\Schemas\AbstractSiteSchemaExtender;
use Capell\Admin\Tests\Fixtures\Autoload\DefaultRelationManagerTestConfigurator;
use Capell\Admin\Tests\Fixtures\Autoload\HideEmptyRelationManagerOwnerModel;
use Capell\Admin\Tests\Fixtures\Autoload\HideEmptyRelationManagerTestManager;
use Capell\Admin\Tests\Fixtures\Autoload\RelationManagerBadgeTestManager;
use Capell\Admin\Tests\Fixtures\Autoload\TestBridgeForAdminBridgeBootTest;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('builds the site details tab with provided components and default groups', function (): void {
    $tab = DetailsTab::make(Schema::make(), [
        TextInput::make('custom_field'),
    ]);
    $childComponents = new ReflectionProperty($tab, 'childComponents');
    $components = $childComponents->getValue($tab)['default'];

    expect($tab)->toBeInstanceOf(Tab::class)
        ->and($tab->getLabel())->toBe(__('capell-admin::tab.details'))
        ->and($tab->getIcon())->toBe('heroicon-o-identification')
        ->and($components)->toHaveCount(5)
        ->and($components[0])->toBeInstanceOf(TextInput::class)
        ->and($components[0]->getName())->toBe('custom_field')
        ->and($components[1])->toBeInstanceOf(Group::class)
        ->and($components[3])->toBeInstanceOf(Group::class)
        ->and($components[4])->toBeInstanceOf(Group::class);
});

it('selects dashboard Filament widgets from install state and available sites', function (): void {
    $capellAccountWidget = 'Capell\\Account\\Filament\\Widgets\\CapellAccountFilamentWidget';
    $filamentInfoWidget = FilamentInfoWidget::class;

    CapellAdmin::shouldReceive('getDashboardFilamentWidgets')
        ->with(DashboardEnum::NotInstalled)
        ->andReturn(['not-installed-widget']);
    CapellAdmin::shouldReceive('getDashboardFilamentWidgets')
        ->with(DashboardEnum::Main)
        ->andReturn(['main-widget', $capellAccountWidget, $filamentInfoWidget]);
    CapellCore::shouldReceive('getPackage')
        ->with(AdminServiceProvider::$packageName)
        ->andReturn(
            adminRemainingSurfacePackage(installed: false),
            adminRemainingSurfacePackage(installed: true),
        );
    CapellCore::shouldReceive('isPackageInstalled')
        ->andReturnTrue();

    expect((new CapellDashboard)->getWidgets())->toBe(['not-installed-widget']);

    $site = Site::factory()->createOne();

    expect((new CapellDashboard)->getWidgets())
        ->toContain('main-widget')
        ->toContain($capellAccountWidget)
        ->toContain($filamentInfoWidget);
});

it('registers extracted admin package bridges as optional integrations', function (): void {
    CapellAdmin::shouldReceive('registerAdminBridge')
        ->once()
        ->with(AdminServiceProvider::$packageName, TestBridgeForAdminBridgeBootTest::class);

    $method = new ReflectionMethod(AdminServiceProvider::class, 'registerOptionalAdminBridge');
    $provider = new AdminServiceProvider(app());
    $method->invoke($provider, TestBridgeForAdminBridgeBootTest::class);
    $method->invoke($provider, 'Capell\\Missing\\UnknownAdminBridge');

    expect(AdminServiceProvider::OPTIONAL_ADMIN_BRIDGES)->toBe([
        'Capell\\HtmlCache\\Support\\Bridges\\HtmlCacheAdminBridge',
    ]);
});

it('returns default page relation managers based on available counts', function (): void {
    $parent = Page::factory()->createOne();
    $pageWithChildren = Page::factory()->parent($parent)->children()->create();
    $pageWithoutRelations = Page::factory()->createOne();

    expect(DefaultRelationManagerTestConfigurator::relationManagers($pageWithChildren))->toBe([
        UrlsRelationManager::class,
        ChildrenRelationManager::class,
        SiblingsRelationManager::class,
    ])
        ->and(DefaultRelationManagerTestConfigurator::relationManagers($pageWithoutRelations))->toBe([
            UrlsRelationManager::class,
        ]);
});

it('provides no-op defaults for schema extenders', function (): void {
    $pageExtender = new class extends AbstractPageSchemaExtender {};
    $siteExtender = new class extends AbstractSiteSchemaExtender {};
    $model = new class extends Model
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        //
    };
    $schema = Schema::make();

    expect($pageExtender)->toBeInstanceOf(PageSchemaExtender::class)
        ->and($pageExtender->extendTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::AfterTitle))->toBe([])
        ->and($pageExtender->extendRelationManagers($model, ['existing']))->toBe(['existing'])
        ->and($pageExtender->extendTabs($schema, ['tab']))->toBe(['tab'])
        ->and($pageExtender->extendSidebarComponents($schema))->toBe([])
        ->and($siteExtender)->toBeInstanceOf(SiteSchemaExtender::class)
        ->and($siteExtender->extendTranslationComponentsForHook($schema, PageTranslationSchemaHookEnum::AfterTitle))->toBe([])
        ->and($siteExtender->extendRelationManagers($model, ['existing']))->toBe(['existing'])
        ->and($siteExtender->extendTabs($schema, ['tab']))->toBe(['tab'])
        ->and($siteExtender->extendSiteMetaDetailsComponents($schema, ['field']))->toBe(['field'])
        ->and($siteExtender->extendCreateWizardComponentsForHook($schema, SiteCreateWizardHookEnum::PagesStepEnd))->toBe([]);
});

it('returns relation manager badges only when related records exist', function (): void {
    $site = Site::factory()->hasSiteDomains(2)->create();
    $emptySite = Site::factory()->createOne();

    expect(RelationManagerBadgeTestManager::getBadge($site, 'page'))->toBe('2')
        ->and(RelationManagerBadgeTestManager::getBadge($emptySite, 'page'))->toBeNull();
});

it('hides relation managers when the owner has no related records', function (): void {
    $site = new HideEmptyRelationManagerOwnerModel(true);
    $emptySite = new HideEmptyRelationManagerOwnerModel(false);

    expect(HideEmptyRelationManagerTestManager::canViewForRecord($site, 'page'))->toBeTrue()
        ->and(HideEmptyRelationManagerTestManager::canViewForRecord($emptySite, 'page'))->toBeFalse();
});

function adminRemainingSurfacePackage(bool $installed): PackageData
{
    return new PackageData(
        name: AdminServiceProvider::$packageName,
        type: PackageTypeEnum::Package,
        installed: $installed,
    );
}
