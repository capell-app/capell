<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionsTable;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Resources\Blueprints\Schemas\BlueprintForm;
use Capell\Admin\Filament\Resources\Blueprints\Tables\BlueprintsTable;
use Capell\Admin\Filament\Resources\Languages\Schemas\LanguageForm;
use Capell\Admin\Filament\Resources\Languages\Tables\LanguagesTable;
use Capell\Admin\Filament\Resources\Layouts\Schemas\LayoutForm;
use Capell\Admin\Filament\Resources\Layouts\Tables\LayoutsTable;
use Capell\Admin\Filament\Resources\Media\Tables\MediaTable;
use Capell\Admin\Filament\Resources\Pages\Schemas\PageForm;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Admin\Filament\Resources\Redirects\Schemas\RedirectForm;
use Capell\Admin\Filament\Resources\Redirects\Tables\RedirectsTable;
use Capell\Admin\Filament\Resources\Sites\Schemas\SiteDomainForm;
use Capell\Admin\Filament\Resources\Sites\Schemas\SiteForm;
use Capell\Admin\Filament\Resources\Sites\Tables\SiteDomainsTable;
use Capell\Admin\Filament\Resources\Sites\Tables\SitesTable;
use Capell\Admin\Filament\Resources\Themes\Schemas\FoundationThemeForm;
use Capell\Admin\Filament\Resources\Themes\Schemas\ThemeForm;
use Capell\Admin\Filament\Resources\Themes\Tables\ThemesTable;
use Capell\Admin\Filament\Resources\Users\Schemas\UserForm;
use Capell\Admin\Filament\Resources\Users\Tables\UsersTable;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Unit\Filament\Fixtures\AdminResourceConfigurationExtensionLivewire;
use Capell\Admin\Tests\Unit\Filament\Fixtures\AdminResourceConfigurationFormLivewire;
use Capell\Admin\Tests\Unit\Filament\Fixtures\AdminResourceConfigurationPageTableLivewire;
use Capell\Admin\Tests\Unit\Filament\Fixtures\AdminResourceConfigurationTableLivewire;
use Capell\Admin\Tests\Unit\Filament\Fixtures\PageSpeedBulkActionPageTableExtenderForTest;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page as PageModel;
use Capell\Core\Models\Site;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    test()->actingAsAdmin();

    $registerConfigurators = new ReflectionMethod(CapellAdminPlugin::class, 'registerConfigurators');
    $registerConfigurators->invoke(CapellAdminPlugin::make());
});

it('builds the primary admin resource table surfaces with their expected controls', function (): void {
    assertAdminTableSurface(
        surface: adminTableSurface(MediaTable::class),
        columns: ['id', 'original_url', 'file_name', 'collection_name', 'mime_type', 'owner_label', 'usage_count', 'created_at'],
        filters: ['collection_name', 'mime_group', 'model_type', 'trashed'],
        recordActions: ['edit', 'open-owner', 'replace-file'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(PagesTable::class),
        columns: ['id', 'name', 'publish_status', 'translation.title', 'url', 'translations.language', 'children_count'],
        filters: ['site_id', 'blueprint_id', 'layout_id', 'filter', 'visible_from', 'trashed', 'system_pages'],
        recordActions: ['visit-page', 'edit'],
        toolbarActions: ['export', 'bulk-publish-pages', 'bulk-schedule-pages', 'bulk-move-pages', 'delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(SitesTable::class),
        columns: ['id', 'name', 'siteDomains.full_url', 'language', 'translations.language', 'theme.name', 'blueprint.name', 'pages_count', 'site_domains_count'],
        filters: ['blueprint_id', 'filter', 'theme_id', 'status', 'trashed'],
        recordActions: ['edit'],
        toolbarActions: ['export', 'delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(ThemesTable::class),
        columns: ['name', 'editor_active_preset', 'sites_count', 'status', 'diagnostics', 'key', 'package'],
        filters: ['blueprint_id', 'status', 'trashed'],
        recordActions: ['previewTheme', 'applyTheme', 'viewThemeDiagnostics', 'edit'],
        toolbarActions: ['delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(ExtensionsTable::class, new AdminResourceConfigurationExtensionLivewire),
        columns: ['name', 'id'],
        filters: ['extension_filters', 'installed_status'],
        recordActions: [
            'viewExtensionDetails',
            'manageExtension',
            'openExtension',
            'installExtension',
            'enableExtension',
            'uninstallExtension',
            'deleteExtension',
        ],
    );
});

it('adds tagged page table extender bulk actions to the pages table toolbar', function (): void {
    app()->bind(PageSpeedBulkActionPageTableExtenderForTest::class);
    app()->tag([PageSpeedBulkActionPageTableExtenderForTest::class], PageTableExtender::TAG);

    expect(adminTableSurface(PagesTable::class)['toolbarActions'])->toContain(
        'run-mobile-page-speed',
        'run-desktop-page-speed',
    );
});

it('eager loads page table relations used by visible row columns', function (): void {
    $livewire = new AdminResourceConfigurationPageTableLivewire;
    $method = new ReflectionMethod(PagesTable::class, 'getTableQuery');

    $query = $method->invoke(null, PageModel::query(), $livewire);

    throw_unless($query instanceof Builder, RuntimeException::class, 'Expected an Eloquent query builder.');

    expect(array_keys($query->getEagerLoads()))->toContain(
        'ancestors',
        'layout',
        'site',
        'translation',
        'blueprint',
        'pageUrls',
        'pageUrl.siteDomain',
        'parent',
        'translations.language',
        'creator',
        'editor',
    );
});

it('does not eager load page table relations for hidden optional columns', function (): void {
    $livewire = new AdminResourceConfigurationPageTableLivewire;
    $livewire->hiddenTableColumns = [
        'parent.name',
        'translations.language',
        'creator.name',
        'editor.name',
    ];
    $method = new ReflectionMethod(PagesTable::class, 'getTableQuery');

    $query = $method->invoke(null, PageModel::query(), $livewire);

    throw_unless($query instanceof Builder, RuntimeException::class, 'Expected an Eloquent query builder.');

    expect(array_keys($query->getEagerLoads()))->not->toContain(
        'parent',
        'translations.language',
        'creator',
        'editor',
    );
});

it('builds secondary admin resource table surfaces with the expected bulk and row actions', function (): void {
    assertAdminTableSurface(
        surface: adminTableSurface(BlueprintsTable::class),
        columns: ['id', 'name', 'key', 'type', 'group', 'count', 'status'],
        filters: ['trashed'],
        recordActions: ['edit'],
        toolbarActions: ['delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(LanguagesTable::class),
        columns: ['id', 'name', 'code', 'locale', 'language', 'sites_count', 'order', 'status'],
        filters: ['status', 'trashed'],
        recordActions: ['edit'],
        toolbarActions: ['delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(LayoutsTable::class),
        columns: ['id', 'name', 'admin.image', 'site.name', 'theme.name', 'pages_count', 'status', 'created_at'],
        filters: ['site_id', 'theme_id', 'trashed'],
        recordActions: ['edit'],
        toolbarActions: ['delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(RedirectsTable::class),
        columns: ['id', 'url', 'target_url', 'status_code', 'is_manual', 'language', 'status', 'hit_count', 'last_hit_at', 'chain_warning'],
        filters: ['status_code', 'is_manual', 'site_id', 'language_id', 'status', 'trashed', 'hit_count_bucket'],
        recordActions: ['edit', 'edit-site', 'edit-language'],
        toolbarActions: ['delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(SiteDomainsTable::class),
        columns: ['id', 'full_url', 'language', 'urls_count', 'status', 'created_at'],
        filters: ['trashed'],
        recordActions: ['edit'],
        toolbarActions: ['delete', 'restore', 'forceDelete'],
    );

    assertAdminTableSurface(
        surface: adminTableSurface(UsersTable::class),
        columns: ['name', 'email', 'roles.name', 'bio', 'email_verified_at', 'created_at'],
        filters: ['roles', 'email_verified_at'],
        recordActions: ['impersonate', 'edit', 'edit-role'],
        toolbarActions: ['delete'],
    );
});

it('builds the major admin resource forms with their expected persisted fields', function (): void {
    Blueprint::factory()->page()->default()->createOne();
    Blueprint::factory()->site()->default()->createOne();
    Blueprint::factory()->theme()->default()->createOne();

    expect(adminFormSurface(PageForm::class, new AdminResourceConfigurationFormLivewire))->toContain('name', 'blueprint_id', 'layout_id', 'site_id', 'translations');
    expect(adminFormSurface(SiteForm::class))->toContain('language_id', 'languages', 'auto_create_pages', 'pages');
    expect(adminFormSurface(SiteDomainForm::class))->toContain('scheme', 'domain', 'path', 'language_id', 'status');
    expect(adminFormSurface(BlueprintForm::class))->toContain('name', 'key', 'type', 'status', 'configurator', 'type_configurator');
    expect(adminFormSurface(LanguageForm::class))->toContain('name', 'code', 'locale', 'default', 'status');
    expect(adminFormSurface(LayoutForm::class))->toContain('name', 'key', 'theme_id', 'status', 'default');
    expect(adminFormSurface(RedirectForm::class))->toContain('site_id', 'language_id', 'url', 'target_url', 'status_code', 'status');
    expect(adminFormSurface(ThemeForm::class))->toContain('blueprint_id', 'name', 'key', 'status', 'primaryColor', 'accentColor', 'mainClass');
    expect(adminFormSurface(FoundationThemeForm::class))->toContain('name', 'key', 'assets', 'assets_path');
    expect(adminFormSurface(UserForm::class))->toContain('name', 'email', 'password', 'roles');
});

it('applies site table language filtering and reports bulk action failures without stopping the batch', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $matchingSite = Site::factory()
        ->language($english)
        ->withTranslations($english)
        ->createOne(['name' => 'English site']);
    $otherSite = Site::factory()
        ->language($welsh)
        ->withTranslations($welsh)
        ->createOne(['name' => 'Welsh site']);

    $table = SitesTable::configure(Table::make(new AdminResourceConfigurationTableLivewire));
    $languageFilter = collect($table->getFilters(withHidden: true))
        ->first(fn (BaseFilter $filter): bool => $filter->getName() === 'filter');

    assert($languageFilter instanceof BaseFilter);

    $filteredIds = $languageFilter
        ->apply(Site::query(), ['language_id' => $english->getKey()])
        ->pluck('id')
        ->all();

    expect($filteredIds)->toContain($matchingSite->getKey())
        ->and($filteredIds)->not->toContain($otherSite->getKey());

    $deleteSpy = bindFakeAction(DeleteSiteAction::class, false);
    $restoreSpy = bindFakeAction(RestoreSiteAction::class, false);
    $sites = new EloquentCollection([$matchingSite, $otherSite]);

    $deleteMethod = new ReflectionMethod(SitesTable::class, 'deleteBulk');
    $deleteMethod->invoke(null, DeleteBulkAction::make('delete'), $sites);

    $restoreMethod = new ReflectionMethod(SitesTable::class, 'restoreBulk');
    $restoreMethod->invoke(null, RestoreBulkAction::make('restore'), $sites);

    expect($deleteSpy->called)->toBeTrue()
        ->and($restoreSpy->called)->toBeTrue();
});

/**
 * @param  array{columns: list<string>, filters: list<string>, recordActions: list<string>, toolbarActions: list<string>}  $surface
 * @param  list<string>  $columns
 * @param  list<string>  $filters
 * @param  list<string>  $recordActions
 * @param  list<string>  $toolbarActions
 */
function assertAdminTableSurface(
    array $surface,
    array $columns,
    array $filters = [],
    array $recordActions = [],
    array $toolbarActions = [],
): void {
    expect($surface['columns'])->toContain(...$columns);

    if ($filters !== []) {
        expect($surface['filters'])->toContain(...$filters);
    }

    if ($recordActions !== []) {
        expect($surface['recordActions'])->toContain(...$recordActions);
    }

    if ($toolbarActions !== []) {
        expect($surface['toolbarActions'])->toContain(...$toolbarActions);
    }
}

/**
 * @param  class-string  $configurator
 * @return array{columns: list<string>, filters: list<string>, recordActions: list<string>, toolbarActions: list<string>}
 */
function adminTableSurface(string $configurator, ?HasTable $livewire = null): array
{
    $table = $configurator::configure(Table::make($livewire ?? new AdminResourceConfigurationTableLivewire));

    return [
        'columns' => adminObjectNames($table->getColumns()),
        'filters' => adminObjectNames($table->getFilters(withHidden: true)),
        'recordActions' => adminObjectNames($table->getRecordActions()),
        'toolbarActions' => adminObjectNames($table->getToolbarActions()),
    ];
}

/**
 * @param  class-string  $configurator
 * @return list<string>
 */
function adminFormSurface(string $configurator, ?Livewire $livewire = null): array
{
    $schema = $configurator::configure(
        Schema::make($livewire ?? Livewire::make())
            ->statePath('data')
            ->operation('create'),
    );

    return array_values(adminSchemaComponentNames($schema->getComponents(withHidden: true))->all());
}

/**
 * @param  array<int|string, mixed>  $objects
 * @return list<string>
 */
function adminObjectNames(array $objects): array
{
    $names = collect($objects)
        ->flatMap(function (mixed $object): array {
            if (is_object($object) && method_exists($object, 'getFlatActions')) {
                return adminObjectNames($object->getFlatActions());
            }

            if (! is_object($object) || ! method_exists($object, 'getName')) {
                return [];
            }

            $name = $object->getName();

            return is_string($name) ? [$name] : [];
        })
        ->values()
        ->all();

    return array_values($names);
}

/**
 * @param  array<int, SchemaComponent>  $components
 * @return Collection<int, string>
 */
function adminSchemaComponentNames(array $components): Collection
{
    return collect($components)
        ->flatMap(function (SchemaComponent $component): array {
            $name = method_exists($component, 'getName') ? $component->getName() : null;
            $children = $component->getChildSchema()?->getComponents(withHidden: true) ?? [];

            return [
                ...(is_string($name) ? [$name] : []),
                ...adminSchemaComponentNames(array_values(array_filter(
                    $children,
                    fn (mixed $child): bool => $child instanceof SchemaComponent,
                )))->all(),
            ];
        })
        ->values();
}
