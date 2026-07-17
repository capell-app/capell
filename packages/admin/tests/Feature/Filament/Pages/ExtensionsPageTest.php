<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionHealthFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionStatsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\InstalledExtensionsFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestSchema;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestSettings;
use Capell\Admin\Tests\Fixtures\Extensions\FakeExtensionCatalogueMetadataProvider;
use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Tests\Fixtures\Filament\Pages\ExampleExtensionPage;
use Capell\Tests\Fixtures\Filament\Pages\PlainRegisteredExtensionPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Tables\Columns\Column;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

uses(CreatesAdminUser::class)->group('extension');

function grantExtensionsPageAccess(): void
{
    Permission::create(['name' => 'View:ExtensionsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
}

function grantExtensionsPageManagementAccess(): void
{
    grantExtensionsPageAccess();

    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

function restorePageNavigation(string $page): void
{
    if (! class_exists($page)) {
        throw new RuntimeException(sprintf('Extension page [%s] is not autoloadable.', $page));
    }

    $reflectionClass = new ReflectionClass($page);
    $reflectionProperty = $reflectionClass->getProperty('shouldRegisterNavigation');
    $reflectionProperty->setValue(null, true);
}

function extensionPackagePath(): string
{
    return __DIR__;
}

function fakeExtensionsPageComposerAvailability(): void
{
    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return true;
        }

        public function has(string $composerName): bool
        {
            return false;
        }
    });
}

afterEach(function (): void {
    restorePageNavigation(SettingsPage::class);
    restorePageNavigation(UpgradePage::class);
    restorePageNavigation(ExampleExtensionPage::class);
    restorePageNavigation(PlainRegisteredExtensionPage::class);
});

it('uses extensions naming for the local extensions page', function (): void {
    expect(ExtensionsPage::getNavigationLabel())->toBe(__('capell-admin::navigation.extensions'))
        ->and(resolve(ExtensionsPage::class)->getTitle())->toBe(__('capell-admin::generic.extensions'))
        ->and(resolve(ExtensionsPage::class)->getSubheading())->toBe(__('capell-admin::generic.extensions_info'));
});

it('does not expose operations tabs on the local extensions page', function (): void {
    expect(method_exists(resolve(ExtensionsPage::class), 'getTabs'))->toBeFalse();
});

it('shows a navigation badge for unhealthy extensions only', function (): void {
    grantExtensionsPageAccess();
    fakeExtensionsPageComposerAvailability();

    $healthyManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/healthy-extension',
        overrides: [
            'displayName' => 'Healthy Extension',
            'version' => '1.0.0',
            'commercial' => [
                'proposedLicense' => 'paid',
            ],
        ],
    ));
    $unhealthyManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/unhealthy-extension',
        overrides: [
            'displayName' => 'Unhealthy Extension',
            'version' => '1.0.0',
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $healthyManifest->name => $healthyManifest,
        $unhealthyManifest->name => $unhealthyManifest,
    ]);

    CapellCore::registerManifestPackage($healthyManifest);
    CapellCore::registerManifestPackage($unhealthyManifest);

    CapellExtension::query()->create([
        'composer_name' => 'vendor/healthy-extension',
        'name' => 'Healthy Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'metadata' => [
            'latest_version' => '1.2.0',
        ],
    ]);
    CapellExtension::query()->create([
        'composer_name' => 'vendor/unhealthy-extension',
        'name' => 'Unhealthy Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    ExtensionHealthAlert::query()->create([
        'alert_id' => 'unhealthy-extension-warning',
        'source' => 'marketplace',
        'composer_name' => 'vendor/unhealthy-extension',
        'severity' => ExtensionHealthAlertSeverity::Warning,
        'category' => ExtensionHealthAlertCategory::Security,
        'title' => 'Security warning',
        'message' => 'Review the unhealthy extension.',
        'required_action' => 'review',
        'runtime_disabled' => false,
        'protected_actions_blocked' => false,
        'issued_at' => now(),
        'signature' => 'test-signature',
    ]);

    expect(ExtensionsPage::getNavigationBadge())->toBe('1')
        ->and(ExtensionsPage::getNavigationBadgeColor())->toBe('warning');
});

it('hides the extension health widget when there are no critical or warning alerts', function (): void {
    grantExtensionsPageAccess();
    fakeExtensionsPageComposerAvailability();

    expect(ExtensionHealthFilamentWidget::canView())->toBeFalse();
});

it('shows the extension health widget when critical or warning alerts exist', function (): void {
    grantExtensionsPageAccess();
    fakeExtensionsPageComposerAvailability();

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/warning-extension',
        overrides: [
            'displayName' => 'Warning Extension',
            'version' => '1.0.0',
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);

    CapellCore::registerManifestPackage($manifest);

    CapellExtension::query()->create([
        'composer_name' => 'vendor/warning-extension',
        'name' => 'Warning Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    ExtensionHealthAlert::query()->create([
        'alert_id' => 'warning-extension-health',
        'source' => 'marketplace',
        'composer_name' => 'vendor/warning-extension',
        'severity' => ExtensionHealthAlertSeverity::Warning,
        'category' => ExtensionHealthAlertCategory::Security,
        'title' => 'Security warning',
        'message' => 'Review the warning extension.',
        'required_action' => 'review',
        'runtime_disabled' => false,
        'protected_actions_blocked' => false,
        'issued_at' => now(),
        'signature' => 'test-signature',
    ]);

    expect(ExtensionHealthFilamentWidget::canView())->toBeTrue();
});

it('renders package contributed content before the extensions table', function (): void {
    grantExtensionsPageAccess();

    app()->bind('tests.extensions-page-extender', fn (): ExtensionsPageExtender => new class implements ExtensionsPageExtender
    {
        /** @return array<int, HtmlString> */
        public function getBeforeTableContent(ExtensionsPage $page): array
        {
            return [new HtmlString('<section>Package contributed alert</section>')];
        }
    });
    app()->tag('tests.extensions-page-extender', ExtensionsPageExtender::TAG);

    Livewire::test(ExtensionActionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertSeeHtml('<section>Package contributed alert</section>');
});

it('does not render an empty extension actions widget shell', function (): void {
    grantExtensionsPageAccess();

    $containerReflection = new ReflectionClass(app());
    $tagsProperty = $containerReflection->getProperty('tags');
    $registeredTags = $tagsProperty->getValue(app());
    $registeredTags[ExtensionsPageExtender::TAG] = [];
    $tagsProperty->setValue(app(), $registeredTags);

    app()->bind('tests.empty-extensions-page-extender', fn (): ExtensionsPageExtender => new class implements ExtensionsPageExtender
    {
        public function getBeforeTableContent(ExtensionsPage $page): array
        {
            return [];
        }
    });
    app()->tag('tests.empty-extensions-page-extender', ExtensionsPageExtender::TAG);

    expect(ExtensionActionsFilamentWidget::canView())->toBeFalse();
});

it('renders extension dashboard Filament widgets as header widgets above the installed extensions table', function (): void {
    grantExtensionsPageAccess();

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        'extensions.stats' => false,
        'extensions.installed' => true,
    ];
    $settings->widget_order = [
        'extensions.installed' => 130,
    ];
    $settings->save();

    $page = resolve(ExtensionsPage::class);
    $method = new ReflectionMethod($page, 'getHeaderWidgets');
    $headerWidgets = $method->invoke($page);

    expect($headerWidgets[0])->toBe(ExtensionStatsOverviewFilamentWidget::class)
        ->and($headerWidgets)->not->toContain(InstalledExtensionsFilamentWidget::class)
        ->and(AdminSettings::instance()->refresh()->enabled_widgets)
        ->toHaveKey('extensions.stats', true);
});

it('uses three extension stats columns on large screens', function (): void {
    $method = new ReflectionMethod(ExtensionStatsOverviewFilamentWidget::class, 'getColumns');

    expect($method->invoke(new ExtensionStatsOverviewFilamentWidget))->toBe([
        'default' => 2,
        'md' => 3,
        'lg' => 5,
    ]);
});

it('does not poll extension stats while the extensions page is idle', function (): void {
    $method = new ReflectionMethod(ExtensionStatsOverviewFilamentWidget::class, 'getPollingInterval');

    expect($method->invoke(new ExtensionStatsOverviewFilamentWidget))->toBeNull();
});

it('can not render extensions page without permission', function (): void {
    test()->actingAsUser();

    get(ExtensionsPage::getUrl())->assertForbidden();
});

it('lists editable extension management entries from registered pages', function (): void {
    grantExtensionsPageAccess();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
        description: 'Local extension description',
    );
    CapellCore::getPackage('vendor/local-extension')->url = 'https://author.example/extensions/local-extension';
    CapellCore::forcePackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertSeeHtml('capell-extension-card-record')
        ->assertSeeHtml('z-index: 0;')
        ->assertSee('Local Extension')
        ->assertSee('Local extension description')
        ->assertSee('1.2.3')
        ->assertDontSee(__('capell-admin::button.download'))
        ->assertDontSee(__('capell-admin::button.remove_package'))
        ->assertTableActionExists('openExtension', fn (Action $action): bool => $action->getTooltip() === null)
        ->assertTableActionHasUrl('openExtension', UpgradePage::getUrl(), record: 'vendor/local-extension')
        ->assertTableActionDoesNotExist('openExtensionWebsite')
        ->assertTableActionExists('enableExtension')
        ->assertTableActionDoesNotExist('disableExtension')
        ->assertTableActionExists('uninstallExtension', fn (Action $action): bool => $action->getTooltip() === null, record: 'vendor/local-extension')
        ->assertTableActionVisible('uninstallExtension', record: 'vendor/local-extension')
        ->assertTableBulkActionDoesNotExist('uninstallExtensions');

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/local-extension');

    expect($extensionRecord['label'] ?? null)->toBe('Local Extension')
        ->and($extensionRecord['primaryUrl'] ?? null)->toBe(UpgradePage::getUrl())
        ->and($extensionRecord['externalUrl'] ?? null)->toBe('https://capell.app/extensions/local-extension');
});

it('renders catalogue release and Capell All metadata on installed extension cards', function (): void {
    grantExtensionsPageAccess();

    CapellCore::registerPackage(
        name: 'capell-app/beta-suite',
        path: extensionPackagePath(),
        version: '1.2.3',
        description: 'Beta extension description',
    );
    CapellCore::forcePackageInstalled('capell-app/beta-suite');
    CapellAdmin::registerExtensionPage('capell-app/beta-suite', UpgradePage::class);

    $provider = new FakeExtensionCatalogueMetadataProvider([
        'capell-app/beta-suite' => new ExtensionCatalogueMetadataData(
            catalogueRole: 'extension',
            maturity: 'beta',
            maturityLabel: 'Beta',
            includedWithCapellAll: true,
        ),
    ]);
    app()->instance(FakeExtensionCatalogueMetadataProvider::class, $provider);
    app()->tag(FakeExtensionCatalogueMetadataProvider::class, ExtensionCatalogueMetadataProvider::TAG);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertSeeHtml('data-release-status="beta"')
        ->assertSeeHtml('data-included-with-capell-all="true"')
        ->assertSeeHtml('data-capell-all-included')
        ->assertSee(__('capell-admin::marketplace.release_status.beta'))
        ->assertSee(__('capell-admin::marketplace.capell_all.included'));

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'capell-app/beta-suite');

    expect($extensionRecord)->toMatchArray([
        'catalogueRole' => 'extension',
        'maturity' => 'beta',
        'maturityLabel' => 'Beta',
        'includedWithCapellAll' => true,
    ]);
});

it('surfaces the package documentation url on extension management entries', function (): void {
    grantExtensionsPageAccess();

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
        description: 'Local extension description',
    );
    CapellCore::getPackage('vendor/local-extension')->documentationUrl = 'https://docs.capell.app/packages/local-extension';
    CapellCore::forcePackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/local-extension');

    expect($extensionRecord['documentationUrl'] ?? null)->toBe('https://docs.capell.app/packages/local-extension');
});

it('searches extension management entries by label and package name', function (): void {
    grantExtensionsPageAccess();

    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage', 'View:UpgradePage');

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
        description: 'Local extension description',
    );
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/local-extension',
        overrides: [
            'displayName' => 'Local Extension',
            'description' => 'Local extension description',
            'version' => '1.2.3',
        ],
    ));
    $peekManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'pboivin/filament-peek',
        overrides: [
            'displayName' => 'Filament Peek',
            'description' => 'Preview pages from the admin panel.',
            'version' => '1.0.0',
            'visibility' => 'support',
            'product' => ['group' => 'Developer tools'],
        ],
    ));
    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
        $peekManifest->name => $peekManifest,
    ]);
    CapellCore::registerManifestPackage($manifest);
    CapellCore::registerManifestPackage($peekManifest);
    CapellCore::forcePackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    CapellCore::registerPackage(
        name: 'pboivin/filament-peek',
        path: extensionPackagePath(),
        version: '1.0.0',
        description: 'Preview pages from the admin panel.',
    );
    CapellCore::getPackage('pboivin/filament-peek')->visibility = 'support';
    CapellCore::forcePackageInstalled('pboivin/filament-peek');

    CapellCore::registerPackage(
        name: 'vendor/analytics-suite',
        path: extensionPackagePath(),
        version: '2.0.0',
        description: 'Insights dashboard tools',
    );
    CapellCore::forcePackageInstalled('vendor/analytics-suite');
    CapellAdmin::registerExtensionPage('vendor/analytics-suite', SettingsPage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->searchTable('analytics')
        ->assertCanSeeTableRecords([
            'vendor/analytics-suite',
        ])
        ->assertCanNotSeeTableRecords([
            'vendor/local-extension',
        ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->searchTable('vendor/local-extension')
        ->assertCanSeeTableRecords([
            'vendor/local-extension',
        ])
        ->assertCanNotSeeTableRecords([
            'vendor/analytics-suite',
        ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->searchTable('peek')
        ->assertCanNotSeeTableRecords([
            'pboivin/filament-peek',
            'vendor/analytics-suite',
            'vendor/local-extension',
        ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertTableFilterExists('extension_filters')
        ->filterTable('extension_filters', ['tag' => 'Developer tools'])
        ->assertCanNotSeeTableRecords([
            'pboivin/filament-peek',
            'vendor/analytics-suite',
            'vendor/local-extension',
        ]);
});

it('hides installer and marketplace packages from the local extensions list', function (): void {
    grantExtensionsPageAccess();

    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage', 'View:UpgradePage');

    foreach (['installer', 'marketplace'] as $packageDirectory) {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../' . $packageDirectory . '/capell.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        throw_unless(is_array($manifest), RuntimeException::class, 'Expected package manifest to decode.');

        CapellCore::registerManifestPackage(CapellManifestData::fromArray($manifest));
        CapellCore::forcePackageInstalled((string) $manifest['name']);
    }

    CapellAdmin::registerExtensionPage('capell-app/installer', SettingsPage::class);
    CapellAdmin::registerExtensionPage('capell-app/marketplace', UpgradePage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([
            'capell-app/installer',
            'capell-app/marketplace',
        ]);
});

it('filters extension cards by installed status while showing all by default', function (): void {
    grantExtensionsPageAccess();

    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage', 'View:UpgradePage');

    CapellCore::registerPackage(
        name: 'vendor/enabled-extension',
        path: extensionPackagePath(),
        version: '1.0.0',
        description: 'Enabled extension description',
    );
    CapellCore::markPackageInstalled('vendor/enabled-extension');
    CapellAdmin::registerExtensionPage('vendor/enabled-extension', SettingsPage::class);

    CapellCore::registerPackage(
        name: 'vendor/disabled-extension',
        path: extensionPackagePath(),
        version: '1.0.0',
        description: 'Disabled extension description',
    );
    CapellCore::markPackageInstalled('vendor/disabled-extension');
    DisablePackageAction::run(CapellCore::getPackage('vendor/disabled-extension'));
    CapellAdmin::registerExtensionPage('vendor/disabled-extension', UpgradePage::class);

    CapellCore::registerPackage(
        name: 'vendor/uninstalled-extension',
        path: extensionPackagePath(),
        version: '1.0.0',
        description: 'Uninstalled extension description',
    );
    CapellAdmin::registerExtensionPage('vendor/uninstalled-extension', UpgradePage::class);

    $page = resolve(ExtensionsPage::class);

    expect(collect($page->getExtensionsData())->pluck('packageName')->all())
        ->toContain('vendor/enabled-extension', 'vendor/disabled-extension', 'vendor/uninstalled-extension');

    expect(collect($page->getExtensionsData(filters: ['installedStatus' => 'installed']))->pluck('packageName')->all())
        ->toContain('vendor/enabled-extension', 'vendor/disabled-extension')
        ->not->toContain('vendor/uninstalled-extension');

    expect(collect($page->getExtensionsData(filters: ['installedStatus' => 'uninstalled']))->pluck('packageName')->all())
        ->toContain('vendor/uninstalled-extension')
        ->not->toContain('vendor/enabled-extension', 'vendor/disabled-extension');

    $page->extensionTablePinnedPackageName = 'vendor/uninstalled-extension';
    $page->extensionTablePinnedIndex = 0;

    expect(collect($page->getExtensionsData())->pluck('packageName')->first())
        ->toBe('vendor/uninstalled-extension');

    Livewire::test(ExtensionsPage::class)
        ->assertDontSee(__('capell-admin::filter.installed_status') . ': ' . __('capell-admin::filter.extensions_all'))
        ->filterTable('installed_status', true)
        ->assertSee(__('capell-admin::filter.installed_status') . ': ' . __('capell-admin::filter.extensions_installed'))
        ->assertCanSeeTableRecords([
            'vendor/enabled-extension',
            'vendor/disabled-extension',
        ])
        ->assertCanNotSeeTableRecords([
            'vendor/uninstalled-extension',
        ])
        ->filterTable('installed_status', false)
        ->assertSee(__('capell-admin::filter.installed_status') . ': ' . __('capell-admin::filter.extensions_uninstalled'))
        ->assertCanSeeTableRecords([
            'vendor/uninstalled-extension',
        ])
        ->assertCanNotSeeTableRecords([
            'vendor/enabled-extension',
            'vendor/disabled-extension',
        ]);
});

it('sorts extension management entries by the latest install or update time by default', function (): void {
    grantExtensionsPageAccess();

    foreach ([
        'vendor/older-extension' => ['Older Extension', now()->subDays(10), now()->subDays(2)],
        'vendor/newly-installed-extension' => ['Newly Installed Extension', now()->subHour(), now()->subHour()],
        'vendor/recently-updated-extension' => ['Recently Updated Extension', now()->subDays(7), now()],
    ] as $packageName => [$label, $installedAt, $updatedAt]) {
        CapellCore::registerPackage(
            name: $packageName,
            path: extensionPackagePath(),
            version: '1.0.0',
            description: $label . ' description',
        );
        CapellCore::forcePackageInstalled($packageName);
        CapellAdmin::registerExtensionPage($packageName, PlainRegisteredExtensionPage::class);

        $extension = CapellExtension::query()->create([
            'composer_name' => $packageName,
            'name' => $label,
            'version' => '1.0.0',
            'status' => ExtensionStatusEnum::Enabled,
            'installed_at' => $installedAt,
        ]);
        $extension->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    expect(collect(resolve(ExtensionsPage::class)->getExtensionsData())->pluck('packageName')->take(3)->all())->toBe([
        'vendor/recently-updated-extension',
        'vendor/newly-installed-extension',
        'vendor/older-extension',
    ]);

    expect(collect(resolve(ExtensionsPage::class)->getExtensionsData(search: 'extension', filters: ['sort' => 'name']))->pluck('packageName')->take(3)->all())->toBe([
        'vendor/newly-installed-extension',
        'vendor/older-extension',
        'vendor/recently-updated-extension',
    ]);

    expect(collect(resolve(ExtensionsPage::class)->getExtensionsData(search: 'extension', filters: ['sort' => 'name_desc']))->pluck('packageName')->take(3)->all())->toBe([
        'vendor/recently-updated-extension',
        'vendor/older-extension',
        'vendor/newly-installed-extension',
    ]);

    Livewire::test(ExtensionsPage::class)
        ->assertTableColumnExists('name', fn (Column $column): bool => $column->isSortable())
        ->set('tableSort', 'name:asc')
        ->assertCanSeeTableRecords([
            'vendor/newly-installed-extension',
            'vendor/older-extension',
            'vendor/recently-updated-extension',
        ], inOrder: true)
        ->set('tableSort', 'name:desc')
        ->assertCanSeeTableRecords([
            'vendor/recently-updated-extension',
            'vendor/older-extension',
            'vendor/newly-installed-extension',
        ], inOrder: true);
});

it('renders manifest metadata for installed extension packages', function (): void {
    grantExtensionsPageAccess();
    fakeExtensionsPageComposerAvailability();

    Permission::create(['name' => 'View:EditorialTools', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:EditorialTools');

    Route::get('/admin/editorial-tools', fn (): string => 'editorial')->name('capell.admin.editorial-tools');

    $packagePath = sys_get_temp_dir() . '/capell-editorial-tools-' . bin2hex(random_bytes(4));
    mkdir($packagePath . '/docs/assets/marketplace', recursive: true);
    file_put_contents(
        $packagePath . '/docs/assets/marketplace/extension-card.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', strict: true),
    );

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-tools',
        surfaces: ['admin'],
        overrides: [
            'displayName' => 'Editorial Tools',
            'version' => '1.0.0',
            'product' => ['group' => 'Publishing', 'tier' => 'premium', 'bundle' => 'publishing'],
            'commercial' => [
                'proposedLicense' => 'paid',
                'requestedCertification' => 'first-party',
                'supportPolicy' => 'priority',
                'privateDocsRequested' => true,
            ],
            'contributes' => [
                [
                    'type' => 'admin-page',
                    'class' => 'Vendor\\EditorialTools\\Pages\\EditorialToolsPage',
                    'label' => 'Editorial tools',
                    'permission' => 'View:EditorialTools',
                    'managementRoute' => 'capell.admin.editorial-tools',
                ],
                [
                    'type' => 'dashboard-widget',
                    'class' => 'Vendor\\EditorialTools\\Widgets\\EditorialQueueWidget',
                    'surface' => 'admin',
                ],
            ],
            'marketplace' => [
                'screenshots' => [
                    [
                        'path' => 'docs/assets/marketplace/extension-card.png',
                        'alt' => 'Editorial tools extension preview image',
                        'caption' => 'Editorial tools extension preview',
                    ],
                ],
            ],
        ],
    ), $packagePath);

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);

    CapellCore::registerManifestPackage($manifest);
    CapellExtension::query()->create([
        'composer_name' => 'vendor/editorial-tools',
        'name' => 'Editorial Tools',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'metadata' => [
            'latest_version' => '1.2.0',
            'certification_status' => 'first-party',
        ],
    ]);

    $blockedManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/blocked-commerce',
        surfaces: ['admin'],
        overrides: [
            'displayName' => 'Blocked Commerce',
            'version' => '1.0.0',
            'product' => ['group' => 'Commerce', 'tier' => 'premium', 'bundle' => 'commerce'],
            'commercial' => [
                'proposedLicense' => 'paid',
                'requestedCertification' => 'community',
                'supportPolicy' => 'standard',
                'privateDocsRequested' => false,
            ],
        ],
    ));
    $freeManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/free-tools',
        surfaces: ['admin'],
        overrides: [
            'displayName' => 'Free Tools',
            'version' => '1.0.0',
            'product' => ['group' => 'Publishing', 'tier' => 'free', 'bundle' => 'starter'],
            'commercial' => [
                'proposedLicense' => 'free',
                'requestedCertification' => 'community',
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
        $blockedManifest->name => $blockedManifest,
    ]);

    CapellCore::registerManifestPackage($blockedManifest);
    CapellExtension::query()->create([
        'composer_name' => 'vendor/blocked-commerce',
        'name' => 'Blocked Commerce',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'revoked',
        'marketplace_runtime_allowed' => false,
    ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableFilterExists('extension_filters')
        ->assertSee('Editorial Tools')
        ->assertSee('Blocked Commerce')
        ->assertTableActionExists('viewExtensionDetails')
        ->mountTableAction('viewExtensionDetails', 'vendor/editorial-tools')
        ->assertMountedActionModalSee('Premium')
        ->assertMountedActionModalSee('First-party')
        ->assertMountedActionModalSee('1.2.0 available')
        ->assertMountedActionModalDontSee('Editorial tools')
        ->assertMountedActionModalDontSee('/admin/editorial-tools')
        ->unmountTableAction()
        ->assertTableActionHasUrl('openExtension', url('/admin/editorial-tools'), record: 'vendor/editorial-tools');

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/editorial-tools');
    $blockedRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/blocked-commerce');
    $extensionRecord = expectPresent($extensionRecord);
    $blockedRecord = expectPresent($blockedRecord);

    expect($extensionRecord['tier'] ?? null)->toBe('premium')
        ->and($extensionRecord['certification'] ?? null)->toBe('first-party')
        ->and($extensionRecord['contributionCount'] ?? null)->toBe(2)
        ->and($extensionRecord['latestVersion'] ?? null)->toBe('1.2.0')
        ->and($extensionRecord['imageUrl'] ?? null)->toContain('/admin/extension-asset')
        ->and($extensionRecord['imageUrls'][0] ?? null)->toContain('/admin/extension-asset')
        ->and($extensionRecord['primaryUrl'] ?? null)->toContain('/admin/editorial-tools')
        ->and($blockedRecord['blocked'] ?? null)->toBeTrue()
        ->and($blockedRecord['contributionCount'] ?? null)->toBe(0);

    $imageUrl = $extensionRecord['imageUrl'] ?? null;

    assert(is_string($imageUrl));

    get($imageUrl)
        ->assertOk();

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
        $blockedManifest->name => $blockedManifest,
        $freeManifest->name => $freeManifest,
    ]);
    CapellCore::registerManifestPackage($freeManifest);
    CapellExtension::query()->create([
        'composer_name' => 'vendor/free-tools',
        'name' => 'Free Tools',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSee('Blocked Commerce')
        ->assertSee('Editorial Tools')
        ->assertSee('Free Tools');

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->filterTable('extension_filters', ['price' => 'paid'])
        ->assertCanSeeTableRecords([
            'vendor/blocked-commerce',
            'vendor/editorial-tools',
        ])
        ->assertCanNotSeeTableRecords([
            'vendor/free-tools',
        ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->filterTable('extension_filters', ['health' => 'critical'])
        ->assertCanSeeTableRecords([
            'vendor/blocked-commerce',
        ])
        ->assertCanNotSeeTableRecords([
            'vendor/editorial-tools',
            'vendor/free-tools',
        ]);
});

it('hides extension lifecycle actions from users without management permission', function (): void {
    Permission::create(['name' => 'View:ExtensionsPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionHidden('enableExtension', record: 'vendor/local-extension')
        ->assertTableActionDoesNotExist('disableExtension')
        ->assertTableActionHidden('uninstallExtension', record: 'vendor/local-extension')
        ->assertTableActionHidden('deleteExtension', record: 'vendor/local-extension')
        ->assertTableBulkActionDoesNotExist('uninstallExtensions');
});

it('does not enable bulk selection on the extensions table', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    CapellCore::registerPackage(
        name: 'vendor/available-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/available-extension');
    CapellCore::registerPackage(
        name: 'vendor/dependent-extension',
        path: extensionPackagePath(),
        version: '1.0.0',
    );
    CapellCore::getPackage('vendor/dependent-extension')->requirements = ['vendor/available-extension'];
    CapellCore::markPackageInstalled('vendor/dependent-extension');
    CapellAdmin::registerExtensionPage('vendor/available-extension', UpgradePage::class);

    $component = Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertSee('Available Extension')
        ->assertTableActionVisible('uninstallExtension', record: 'vendor/available-extension')
        ->assertTableActionEnabled('uninstallExtension', record: 'vendor/available-extension')
        ->assertTableBulkActionDoesNotExist('uninstallExtensions');

    expect($component->instance()->getTable()->isSelectionEnabled())->toBeFalse();
});

it('can re-enable a disabled extension from the extensions page', function (): void {
    grantExtensionsPageManagementAccess();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/local-extension');
    DisablePackageAction::run(CapellCore::getPackage('vendor/local-extension'));
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionVisible('enableExtension', record: 'vendor/local-extension')
        ->callTableAction('enableExtension', record: 'vendor/local-extension')
        ->assertDispatched('refresh-sidebar')
        ->assertTableActionHidden('enableExtension', record: 'vendor/local-extension')
        ->assertTableActionDoesNotExist('disableExtension');

    $enabledExtension = CapellExtension::query()
        ->where('composer_name', 'vendor/local-extension')
        ->first();

    expect($enabledExtension?->status)->toBe(ExtensionStatusEnum::Enabled)
        ->and(CapellCore::isPackageEnabled('vendor/local-extension'))->toBeTrue();
});

it('can install an uninstalled local extension from the extensions page', function (): void {
    grantExtensionsPageManagementAccess();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionVisible('installExtension', record: 'vendor/local-extension')
        ->callTableAction('installExtension', record: 'vendor/local-extension')
        ->assertDispatched('refresh-sidebar')
        ->assertNotified(__('capell-admin::message.extension_installed', [
            'extension' => 'Local Extension',
        ]))
        ->assertTableActionHidden('installExtension', record: 'vendor/local-extension');

    expect(CapellCore::isPackageInstalled('vendor/local-extension'))->toBeTrue();
});

it('shows uninstall actions for trusted package entries', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        version: '1.2.3',
    );
    CapellAdmin::registerExtensionPage('capell-app/frontend', UpgradePage::class);

    $component = Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionHidden('enableExtension', record: 'capell-app/frontend')
        ->assertTableActionVisible('uninstallExtension', record: 'capell-app/frontend')
        ->assertTableActionEnabled('uninstallExtension', record: 'capell-app/frontend')
        ->assertTableActionHidden('deleteExtension', record: 'capell-app/frontend')
        ->assertTableActionDoesNotExist('disableExtension')
        ->mountTableAction('uninstallExtension', 'capell-app/frontend')
        ->assertMountedActionModalSee(__('capell-admin::generic.uninstall_extension_description'))
        ->assertMountedActionModalDontSee(__('capell-admin::generic.extension_removal_mode_uninstall_only'))
        ->assertMountedActionModalDontSee(__('capell-admin::generic.extension_removal_mode_delete_package'))
        ->unmountTableAction();

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'capell-app/frontend');

    expect($extensionRecord['core'] ?? null)->toBeTrue()
        ->and($extensionRecord['installed'] ?? null)->toBeTrue()
        ->and($extensionRecord['enabled'] ?? null)->toBeTrue()
        ->and(CapellCore::getDependentInstalledPackages('capell-app/frontend')->pluck('name')->all())->toBe([]);

    $component
        ->callTableAction('uninstallExtension', record: 'capell-app/frontend')
        ->assertDispatched('refresh-sidebar')
        ->assertNotified(__('capell-admin::message.extension_uninstalled', [
            'extension' => 'Capell Frontend',
        ]));

    expect(CapellCore::isPackageInstalled('capell-app/frontend'))->toBeFalse();

    expect($component->instance()->getTable()->isSelectionEnabled())->toBeFalse();
});

it('can uninstall one extension from the extensions page', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionVisible('uninstallExtension', record: 'vendor/local-extension')
        ->assertTableActionHidden('deleteExtension', record: 'vendor/local-extension')
        ->mountTableAction('uninstallExtension', 'vendor/local-extension')
        ->assertMountedActionModalSee(__('capell-admin::generic.uninstall_extension_description'))
        ->assertMountedActionModalSee(__('capell-admin::generic.delete_extension_package'))
        ->assertMountedActionModalSee(__('capell-admin::generic.delete_extension_data'))
        ->assertMountedActionModalDontSee(__('capell-admin::generic.extension_removal_mode_uninstall_only'))
        ->assertMountedActionModalDontSee(__('capell-admin::generic.extension_removal_mode_delete_package'))
        ->unmountTableAction()
        ->assertTableBulkActionDoesNotExist('uninstallExtensions')
        ->callTableAction('uninstallExtension', record: 'vendor/local-extension')
        ->assertDispatched('refresh-sidebar')
        ->assertNotified(__('capell-admin::message.extension_uninstalled', [
            'extension' => 'Local Extension',
        ]));

    expect(CapellExtension::query()->where('composer_name', 'vendor/local-extension')->exists())->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/local-extension'))->toBeFalse();
});

it('can uninstall one extension from its card while keeping package files locally', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    $component = Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionVisible('uninstallExtension', record: 'vendor/local-extension')
        ->assertTableActionHidden('deleteExtension', record: 'vendor/local-extension')
        ->callTableAction('uninstallExtension', record: 'vendor/local-extension')
        ->assertDispatched('refresh-sidebar')
        ->assertNotified(__('capell-admin::message.extension_uninstalled', [
            'extension' => 'Local Extension',
        ]));

    $component
        ->assertCanSeeTableRecords(['vendor/local-extension'])
        ->assertTableActionVisible('deleteExtension', record: 'vendor/local-extension');

    expect(CapellExtension::query()->where('composer_name', 'vendor/local-extension')->exists())->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/local-extension'))->toBeFalse()
        ->and(CapellCore::isPackageAvailable('vendor/local-extension'))->toBeTrue();
});

it('requires explicit confirmation before uninstalling an extension with dependents', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    CapellCore::registerPackage(
        name: 'vendor/base-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/base-extension');
    CapellCore::registerPackage(
        name: 'vendor/middle-extension',
        path: extensionPackagePath(),
        version: '1.0.0',
    );
    CapellCore::getPackage('vendor/middle-extension')->requirements = ['vendor/base-extension'];
    CapellCore::markPackageInstalled('vendor/middle-extension');
    CapellCore::registerPackage(
        name: 'vendor/top-extension',
        path: extensionPackagePath(),
        version: '1.0.0',
    );
    CapellCore::getPackage('vendor/top-extension')->requirements = ['vendor/middle-extension'];
    CapellCore::markPackageInstalled('vendor/top-extension');
    CapellAdmin::registerExtensionPage('vendor/base-extension', UpgradePage::class);

    $component = Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionVisible('uninstallExtension', record: 'vendor/base-extension')
        ->assertTableActionEnabled('uninstallExtension', record: 'vendor/base-extension')
        ->mountTableAction('uninstallExtension', 'vendor/base-extension')
        ->assertMountedActionModalSee(trans_choice('capell-admin::generic.extension_uninstall_blocked_modal_dependents', 2, [
            'extensions' => 'Top Extension (vendor/top-extension), Middle Extension (vendor/middle-extension)',
        ]))
        ->assertMountedActionModalSee(__('capell-admin::generic.extension_uninstall_confirm_packages_label'))
        ->assertMountedActionModalSee('Top Extension (vendor/top-extension)')
        ->assertMountedActionModalSee('Middle Extension (vendor/middle-extension)')
        ->assertMountedActionModalSee('Base Extension (vendor/base-extension)')
        ->assertMountedActionModalDontSee(__('capell-admin::generic.extension_removal_mode_uninstall_only'))
        ->setTableActionData([
            'confirmed_package_names' => ['vendor/base-extension', 'vendor/middle-extension'],
        ])
        ->callMountedTableAction()
        ->assertNotified(__('capell-admin::message.extension_uninstall_confirmation_required'))
        ->assertNotNotified(__('capell-admin::message.extension_uninstalled', [
            'extension' => 'Base Extension',
        ]))
        ->callTableAction('uninstallExtension', record: 'vendor/base-extension', data: [
            'confirmed_package_names' => [
                'vendor/top-extension',
                'vendor/middle-extension',
                'vendor/base-extension',
            ],
        ])
        ->assertNotified(__('capell-admin::message.extensions_uninstalled', [
            'extensions' => 'Top Extension, Middle Extension, Base Extension',
        ]));

    $extensionRecord = collect($component->instance()->getExtensionsData(filters: ['installedStatus' => 'all']))
        ->firstWhere('id', 'vendor/base-extension');

    expect($extensionRecord)->toBeArray()
        ->and($extensionRecord['installed'] ?? null)->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/base-extension'))->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/middle-extension'))->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/top-extension'))->toBeFalse()
        ->and($component->instance()->getTable()->isSelectionEnabled())->toBeFalse();
});

it('shows an unavailable package modal for registry-missing installed records', function (): void {
    grantExtensionsPageManagementAccess();

    CapellExtension::query()->create([
        'composer_name' => 'vendor/missing-extension',
        'name' => 'Missing Extension',
        'description' => 'Local package is no longer registered.',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertSee('Missing Extension')
        ->assertTableActionVisible('uninstallExtension', record: 'vendor/missing-extension')
        ->assertTableActionEnabled('uninstallExtension', record: 'vendor/missing-extension')
        ->assertTableActionHidden('deleteExtension', record: 'vendor/missing-extension')
        ->assertTableActionExists('uninstallExtension', fn (Action $action): bool => ! $action->getModalSubmitAction() instanceof Action
            && $action->getModalCancelActionLabel() === __('capell-admin::button.close')
            && $action->getTooltip() === __('capell-admin::generic.extension_uninstall_blocked_package_unavailable'), record: 'vendor/missing-extension')
        ->mountTableAction('uninstallExtension', 'vendor/missing-extension')
        ->assertMountedActionModalSee(__('capell-admin::generic.extension_uninstall_blocked_package_unavailable'))
        ->assertMountedActionModalDontSee(__('capell-admin::generic.extension_removal_mode_uninstall_only'))
        ->unmountTableAction()
        ->callTableAction('uninstallExtension', record: 'vendor/missing-extension')
        ->assertNotNotified(__('capell-admin::message.extension_uninstalled', [
            'extension' => 'Missing Extension',
        ]));

    expect(CapellExtension::query()->where('composer_name', 'vendor/missing-extension')->exists())->toBeTrue();
});

it('can uninstall one extension and remove its Composer package files when requested', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);
    bindSuccessfulExtensionsPageComposerRemoveProcess('vendor/local-extension');

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->mountTableAction('uninstallExtension', 'vendor/local-extension')
        ->assertMountedActionModalSee(__('capell-admin::generic.delete_extension_package'))
        ->setTableActionData([
            'delete_extension_package' => true,
        ])
        ->callMountedTableAction()
        ->assertNotified(__('capell-admin::message.extension_deleted', [
            'extension' => 'Local Extension',
        ]));

    expect(CapellExtension::query()->where('composer_name', 'vendor/local-extension')->exists())->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/local-extension'))->toBeFalse();
});

it('shows a focused failure notification when Composer package deletion fails', function (): void {
    grantExtensionsPageManagementAccess();
    fakeExtensionsPageComposerAvailability();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/local-extension');
    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);
    bindFailingExtensionsPageComposerRemoveProcess('vendor/local-extension');

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->callTableAction('uninstallExtension', record: 'vendor/local-extension')
        ->assertTableActionVisible('deleteExtension', record: 'vendor/local-extension')
        ->callTableAction('deleteExtension', record: 'vendor/local-extension')
        ->assertNotified(__('capell-admin::message.extension_uninstall_failed', [
            'extension' => 'Local Extension',
        ]));

    expect(CapellExtension::query()->where('composer_name', 'vendor/local-extension')->exists())->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/local-extension'))->toBeFalse();
});

it('lists installed packages without a registered extension page in the installed widget', function (): void {
    grantExtensionsPageAccess();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
        description: 'Local extension description',
    );
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/local-extension',
        overrides: [
            'displayName' => 'Local Extension',
            'description' => 'Local extension description',
            'version' => '1.2.3',
        ],
    ));
    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);
    CapellCore::registerManifestPackage($manifest);
    CapellCore::forcePackageInstalled('vendor/local-extension');
    CapellExtension::query()->create([
        'composer_name' => 'vendor/local-extension',
        'name' => 'Local Extension',
        'description' => 'Local extension description',
        'version' => '1.2.3',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertSee('Local Extension')
        ->assertSee('Local extension description');

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/local-extension');

    expect($extensionRecord)->not->toBeNull()
        ->and($extensionRecord['installed'] ?? null)->toBeTrue();
});

it('opens primary extension settings through a direct settings link', function (): void {
    grantExtensionsPageAccess();

    CapellCore::registerPackage(
        name: 'vendor/local-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::forcePackageInstalled('vendor/local-extension');
    try {
        Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
        Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
        test()->authenticatedUser()->givePermissionTo('View:SettingsPage', 'View:UpgradePage');
        CapellAdmin::registerExtensionPage('vendor/local-extension', SettingsPage::class);
        CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

        $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
            ->firstWhere('id', 'vendor/local-extension');

        Livewire::test(InstalledExtensionsFilamentWidget::class)
            ->assertSuccessful()
            ->assertTableActionExists('viewExtensionDetails')
            ->mountTableAction('viewExtensionDetails', 'vendor/local-extension')
            ->assertMountedActionModalDontSee(SettingsPage::getUrl())
            ->assertMountedActionModalDontSee(UpgradePage::getUrl())
            ->unmountTableAction()
            ->assertTableActionHasUrl('openExtension', SettingsPage::getUrl(), record: 'vendor/local-extension');

        expect($extensionRecord['secondaryPages'] ?? [])->toHaveCount(1)
            ->and($extensionRecord['primaryUrl'] ?? null)->toBe(SettingsPage::getUrl())
            ->and($extensionRecord['secondaryPages'][0]['label'] ?? null)->toBe(__('capell-admin::navigation.upgrade'))
            ->and($extensionRecord['secondaryPages'][0]['url'] ?? null)->toBe(UpgradePage::getUrl());
    } finally {
        restorePageNavigation(UpgradePage::class);
    }
});

it('opens package settings as a native extension management modal', function (): void {
    grantExtensionsPageAccess();

    CapellCore::registerPackage(
        name: 'vendor/settings-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::forcePackageInstalled('vendor/settings-extension');

    app()->instance(AbstractPackageSettingsPageTestSettings::class, new AbstractPackageSettingsPageTestSettings([
        'headline' => 'Existing setting',
    ]));

    $settingsRegistry = resolve(SettingsSchemaRegistry::class);
    $settingsRegistry->registerSettingsClass('abstract-page-test', AbstractPackageSettingsPageTestSettings::class);
    $settingsRegistry->registerMetadata(new SettingsGroupMetadata(
        group: 'abstract-page-test',
        label: 'Extension settings',
        packageName: 'vendor/settings-extension',
    ));
    $settingsRegistry->register('abstract-page-test', AbstractPackageSettingsPageTestSchema::class);
    CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
        packageName: 'vendor/settings-extension',
        label: 'Extension settings',
        settingsGroup: 'abstract-page-test',
    ));

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertTableActionVisible('manageExtension', record: 'vendor/settings-extension')
        ->assertTableActionHidden('openExtension', record: 'vendor/settings-extension')
        ->mountTableAction('manageExtension', 'vendor/settings-extension')
        ->assertMountedActionModalSee('Extension settings')
        ->assertSchemaStateSet([
            'headline' => 'Existing setting',
        ]);

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/settings-extension');

    expect($extensionRecord['primaryUrl'] ?? null)->toBeNull()
        ->and($extensionRecord['managementSurfaces'][0]['type'] ?? null)->toBe('settings')
        ->and($extensionRecord['managementSurfaces'][0]['settingsGroup'] ?? null)->toBe('abstract-page-test');
});

it('opens package settings through URL query parameters', function (): void {
    grantExtensionsPageAccess();

    CapellCore::registerPackage(
        name: 'vendor/settings-extension',
        path: extensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::forcePackageInstalled('vendor/settings-extension');

    app()->instance(AbstractPackageSettingsPageTestSettings::class, new AbstractPackageSettingsPageTestSettings([
        'headline' => 'Existing setting',
    ]));

    $settingsRegistry = resolve(SettingsSchemaRegistry::class);
    $settingsRegistry->registerSettingsClass('abstract-page-test', AbstractPackageSettingsPageTestSettings::class);
    $settingsRegistry->register('abstract-page-test', AbstractPackageSettingsPageTestSchema::class);
    CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
        packageName: 'vendor/settings-extension',
        label: 'Extension settings',
        settingsGroup: 'abstract-page-test',
    ));

    Livewire::withQueryParams([
        'manage' => 'vendor/settings-extension',
        'surface' => 'abstract-page-test',
    ])
        ->test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertMountedActionModalSee('Extension settings')
        ->assertSchemaStateSet([
            'headline' => 'Existing setting',
        ]);
});

it('does not expose settings management for extensions without a management page', function (): void {
    grantExtensionsPageAccess();
    fakeExtensionsPageComposerAvailability();

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/settings-only-extension',
        surfaces: ['admin'],
        overrides: [
            'displayName' => 'Settings Only Extension',
            'description' => 'Settings are registered elsewhere.',
            'version' => '1.0.0',
            'database' => [
                'settings' => true,
            ],
            'settings' => [
                'Vendor\\SettingsOnly\\Settings',
            ],
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        $manifest->name => $manifest,
    ]);

    CapellCore::registerManifestPackage($manifest);
    CapellExtension::query()->create([
        'composer_name' => 'vendor/settings-only-extension',
        'name' => 'Settings Only Extension',
        'description' => 'Settings are registered elsewhere.',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    Livewire::test(InstalledExtensionsFilamentWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([
            'vendor/settings-only-extension',
        ])
        ->assertTableActionHidden('manageExtension', record: 'vendor/settings-only-extension');

    $extensionRecord = collect((new InstalledExtensionsFilamentWidget)->getExtensionsData())
        ->firstWhere('id', 'vendor/settings-only-extension');

    expect($extensionRecord['primaryUrl'] ?? null)->toBeNull()
        ->and($extensionRecord['secondaryPages'] ?? [])->toBe([]);
});

it('does not document extension authoring on the extensions page', function (): void {
    grantExtensionsPageAccess();

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertDontSee('Need to add a capability? Create a Capell extension.')
        ->assertDontSee('https://docs.capell.app/packages/build-extension-end-to-end');
});

it('registers package owned extension pages without extension groups', function (): void {
    CapellAdmin::registerExtensionPage('vendor/local-extension', ExampleExtensionPage::class);
    CapellAdmin::registerExtensionPage('vendor/local-extension', PlainRegisteredExtensionPage::class);

    expect(CapellAdmin::getAdminSurfaceRegistry()->pages())->toContain(
        ExampleExtensionPage::class,
        PlainRegisteredExtensionPage::class,
    )
        ->and(ExampleExtensionPage::shouldRegisterNavigation())->toBeFalse()
        ->and(resolve(ExtensionPageRegistry::class)->get('vendor/local-extension'))->toBe(ExampleExtensionPage::class)
        ->and(collect(resolve(ExtensionPageRegistry::class)->entries())
            ->where('packageName', 'vendor/local-extension')
            ->values()
            ->all())->toBe([
                [
                    'packageName' => 'vendor/local-extension',
                    'page' => ExampleExtensionPage::class,
                ],
                [
                    'packageName' => 'vendor/local-extension',
                    'page' => PlainRegisteredExtensionPage::class,
                ],
            ]);
});

it('keeps extension page navigation out of the primary sidebar child items', function (): void {
    grantExtensionsPageAccess();

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    CapellAdmin::registerExtensionPage('vendor/local-extension', UpgradePage::class);

    $extensionNavigationItems = collect(CapellAdmin::getNavigationItems())
        ->filter(fn (NavigationItem $item): bool => $item->getParentItem() === ExtensionsPage::getNavigationLabel())
        ->values()
        ->all();

    expect($extensionNavigationItems)->toBe([]);
});

it('keeps the extensions hub free of sub navigation and moves sibling extension pages into header actions', function (): void {
    CapellAdmin::registerExtensionPage('vendor/local-extension', ExampleExtensionPage::class);
    CapellAdmin::registerExtensionPage('vendor/local-extension', PlainRegisteredExtensionPage::class);
    CapellAdmin::registerExtensionPage('vendor/analytics-suite', SettingsPage::class);

    $page = resolve(ExampleExtensionPage::class);
    $headerActionGroups = (fn (): array => $this->getHeaderActions())->call($page);
    $headerActions = collect($headerActionGroups[0]->getActions())
        ->map(fn (Action $action): string => filamentText($action->getLabel()))
        ->values()
        ->all();

    expect($page->getBreadcrumbs())->toBe([
        ExtensionsPage::getUrl() => ExtensionsPage::getNavigationLabel(),
        ExampleExtensionPage::getNavigationLabel(),
    ])
        ->and($headerActionGroups)->toHaveCount(1)
        ->and($headerActions)->toBe([
            PlainRegisteredExtensionPage::getNavigationLabel(),
        ])
        ->and(resolve(ExtensionPageRegistry::class)->pagesForPackage('vendor/local-extension'))->toBe([
            ExampleExtensionPage::class,
            PlainRegisteredExtensionPage::class,
        ])
        ->and(resolve(ExtensionPageRegistry::class)->packageNameForPage(ExampleExtensionPage::class))->toBe('vendor/local-extension');
});

it('does not suppress core page navigation when registering extension pages', function (): void {
    CapellAdmin::registerExtensionPage('vendor/local-extension', PlainRegisteredExtensionPage::class);

    expect(PlainRegisteredExtensionPage::shouldRegisterNavigation())->toBeFalse()
        ->and(CapellDashboard::shouldRegisterNavigation())->toBeTrue()
        ->and(ExtensionsPage::shouldRegisterNavigation())->toBeTrue();
});

it('keeps every registered extension page for a package', function (): void {
    CapellAdmin::registerExtensionPage('vendor/local-extension', ExampleExtensionPage::class);
    CapellAdmin::registerExtensionPage('vendor/local-extension', PlainRegisteredExtensionPage::class);

    expect(resolve(ExtensionPageRegistry::class)->get('vendor/local-extension'))->toBe(ExampleExtensionPage::class)
        ->and(resolve(ExtensionPageRegistry::class)->all())->toContain(
            ExampleExtensionPage::class,
            PlainRegisteredExtensionPage::class,
        );
});

function bindSuccessfulExtensionsPageComposerRemoveProcess(string $packageName): void
{
    bindMixedExtensionsPageComposerRemoveProcesses([$packageName => true]);
}

function bindFailingExtensionsPageComposerRemoveProcess(string $packageName): void
{
    bindMixedExtensionsPageComposerRemoveProcesses([$packageName => false]);
}

/**
 * @param  array<string, bool>  $packageOutcomes
 */
function bindMixedExtensionsPageComposerRemoveProcesses(array $packageOutcomes): void
{
    $factory = Mockery::mock(ProcessFactoryInterface::class);

    $factory
        ->shouldReceive('make')
        ->with(Mockery::type('array'), Mockery::type('string'))
        ->times(count($packageOutcomes))
        ->andReturnUsing(function (array $command) use ($packageOutcomes): Process {
            $packageName = (string) ($command[2] ?? '');

            return extensionsPageComposerRemoveProcess($packageName, $packageOutcomes[$packageName] ?? false);
        });

    app()->instance(ProcessFactoryInterface::class, $factory);
}

function extensionsPageComposerRemoveProcess(string $packageName, bool $successful): Process
{
    $process = Mockery::mock(Process::class);

    $process
        ->shouldReceive('setEnv')
        ->with(Mockery::type('array'))
        ->andReturnSelf();

    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();

    $process
        ->shouldReceive('run')
        ->andReturn(0);

    $process
        ->shouldReceive('getErrorOutput')
        ->andReturn($successful ? '' : sprintf('Could not remove %s', $packageName));

    $process
        ->shouldReceive('getOutput')
        ->andReturn(sprintf('Package %s removed', $packageName));

    $process
        ->shouldReceive('isSuccessful')
        ->andReturn($successful);

    return $process;
}
