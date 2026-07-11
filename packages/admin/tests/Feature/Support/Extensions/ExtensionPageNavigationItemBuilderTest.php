<?php

declare(strict_types=1);

use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionPageNavigationItemBuilder;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Tests\Feature\Support\Extensions\Fixtures\BuilderBrokenUrlExtensionPage;
use Capell\Admin\Tests\Feature\Support\Extensions\Fixtures\BuilderExampleExtensionPage;
use Capell\Admin\Tests\Feature\Support\Extensions\Fixtures\BuilderInaccessibleExtensionPage;
use Capell\Admin\Tests\Feature\Support\Extensions\Fixtures\BuilderPlainExtensionPage;
use Capell\Admin\Tests\Feature\Support\Extensions\Fixtures\BuilderUnregisteredExtensionPage;
use Capell\Core\Facades\CapellCore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('builds grouped extension page navigation and settings surface siblings', function (): void {
    grantExtensionNavigationBuilderManagementAccess();

    CapellCore::registerPackage(
        name: 'vendor/content-tools',
        path: __DIR__,
        version: '1.0.0',
    );
    CapellCore::getPackage('vendor/content-tools')->productGroup = 'Content tools';
    CapellCore::forcePackageInstalled('vendor/content-tools');

    CapellAdmin::registerExtensionPage('vendor/content-tools', BuilderExampleExtensionPage::class);
    CapellAdmin::registerExtensionPage('vendor/content-tools', BuilderPlainExtensionPage::class);
    CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
        packageName: 'vendor/content-tools',
        label: 'Builder package settings',
        settingsGroup: 'builder-settings',
        icon: 'heroicon-o-cog-6-tooth',
    ));

    $builder = new ExtensionPageNavigationItemBuilder;
    $groups = $builder->groupedItems();
    $items = $builder->items();
    $siblings = $builder->siblingItemsForPage(BuilderExampleExtensionPage::class);

    expect($groups)->toHaveCount(1)
        ->and(filamentText($groups[0]->getLabel()))->toBe('Content tools')
        ->and(extensionNavigationBuilderLabels($items))->toBe([
            BuilderExampleExtensionPage::getNavigationLabel(),
            BuilderPlainExtensionPage::getNavigationLabel(),
            'Builder package settings',
        ])
        ->and(extensionNavigationBuilderLabels($siblings))->toBe([
            BuilderExampleExtensionPage::getNavigationLabel(),
            BuilderPlainExtensionPage::getNavigationLabel(),
            'Builder package settings',
        ])
        ->and($siblings[2]->getUrl())->toBe(ExtensionsPage::getUrl([
            'manage' => 'vendor/content-tools',
            'surface' => 'builder-settings',
        ]));
});

it('omits inaccessible and broken extension navigation entries', function (): void {
    test()->actingAsUser();

    resolve(ExtensionPageRegistry::class)->register('vendor/unlisted', BuilderInaccessibleExtensionPage::class);
    resolve(ExtensionPageRegistry::class)->register('vendor/unlisted', BuilderBrokenUrlExtensionPage::class);
    resolve(ExtensionPageRegistry::class)->register('vendor/unlisted', BuilderPlainExtensionPage::class);
    CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
        packageName: 'vendor/unlisted',
        label: 'Hidden settings',
        settingsGroup: 'hidden-settings',
    ));

    $builder = new ExtensionPageNavigationItemBuilder;

    expect(extensionNavigationBuilderLabels($builder->items()))->toBe([
        BuilderPlainExtensionPage::getNavigationLabel(),
    ])
        ->and(extensionNavigationBuilderLabels($builder->siblingItemsForPage(BuilderPlainExtensionPage::class)))->toBe([
            BuilderPlainExtensionPage::getNavigationLabel(),
        ])
        ->and($builder->siblingItemsForPage(BuilderUnregisteredExtensionPage::class))->toBe([]);
});

function grantExtensionNavigationBuilderManagementAccess(): void
{
    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

/**
 * @param  list<object>  $items
 * @return list<string>
 */
function extensionNavigationBuilderLabels(array $items): array
{
    $labels = [];

    foreach ($items as $item) {
        $labels[] = filamentText(filamentObjectMethod($item, 'getLabel'));
    }

    return $labels;
}
