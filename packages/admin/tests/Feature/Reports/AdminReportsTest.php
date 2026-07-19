<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildPackageReadinessReportAction;
use Capell\Admin\Actions\Reports\BuildPublicRenderSafetyReportAction;
use Capell\Admin\Actions\Reports\BuildReportVisibilityFormStateAction;
use Capell\Admin\Actions\Reports\NormalizeReportVisibilitySettingsAction;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\Reports\PackageReadinessReport;
use Capell\Admin\Filament\Pages\Reports\PublicRenderSafetyReport;
use Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\CustomReportPageForRegistry;
use Capell\Admin\Tests\Fixtures\Autoload\MissingReportPageForTest;
use Capell\Admin\Tests\Fixtures\Autoload\PlainReportPageForTest;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('admin', 'reports');

beforeEach(function (): void {
    Permission::findOrCreate('View:SettingsPage', 'web');
    Permission::findOrCreate('View:PublicRenderSafetyReport', 'web');
    Role::findOrCreate('editor', 'web');
});

it('registers installed core report metadata and page classes', function (): void {
    $reports = CapellAdmin::getReports();
    $coreReportKeys = [
        'core.accessibility_readiness',
        'core.publishing_readiness',
        'core.demo_install_health',
        'core.package_readiness',
        'core.public_render_safety',
    ];
    $corePages = [
        ...array_column(PageEnum::cases(), 'value'),
        ...array_column(array_intersect_key($reports, array_flip($coreReportKeys)), 'pageClass'),
    ];
    $coreWidgets = array_column(FilamentWidgetEnum::cases(), 'value');
    $adminSurfaces = CapellAdmin::getAdminSurfaceRegistry();

    expect(array_values(array_intersect(array_keys($reports), $coreReportKeys)))->toBe($coreReportKeys)
        ->and($reports['core.public_render_safety']->pageClass)->toBe(PublicRenderSafetyReport::class)
        ->and($reports['core.package_readiness']->pageClass)->toBe(PackageReadinessReport::class)
        ->and(array_values(array_intersect($adminSurfaces->pages(), $corePages)))->toBe($corePages)
        ->and(array_values(array_intersect($adminSurfaces->resources(), array_column(ResourceEnum::cases(), 'value'))))->toBe(array_column(ResourceEnum::cases(), 'value'))
        ->and(array_values(array_intersect($adminSurfaces->widgets(), $coreWidgets)))->toBe($coreWidgets);
});

it('loads report visibility controls grouped by role in admin settings', function (): void {
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    $state = Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::reports.roles_helper'))
        ->assertSee(__('capell-admin::reports.reports_helper'))
        ->get('data.admin.report_visibility');

    expect($state)->toBeArray()
        ->and(collect($state)->pluck('role_name')->all())->toContain('editor')
        ->and(collect($state)->flatMap(fn (array $role): array => $role['reports'])->pluck('report_key')->all())
        ->toContain('core.public_render_safety');
});

it('builds report visibility form state with role labels and persisted defaults', function (): void {
    Role::findOrCreate('content_admin', 'web');

    $settings = AdminSettings::instance();
    $settings->enabled_reports_by_role = [
        'content_admin' => [
            'core.public_render_safety' => false,
        ],
    ];

    $state = BuildReportVisibilityFormStateAction::run($settings);
    $contentAdminState = collect($state)->firstWhere('role_name', 'content_admin');
    $publicRenderSafetyReport = collect($contentAdminState['reports'] ?? [])
        ->firstWhere('report_key', 'core.public_render_safety');

    expect($contentAdminState)->not->toBeNull()
        ->and($contentAdminState['role_label'])->toBe('Content Admin')
        ->and($publicRenderSafetyReport)->not->toBeNull()
        ->and($publicRenderSafetyReport['report_label'])->toBe('Public Render Safety')
        ->and($publicRenderSafetyReport['report_description'])->toBe(__('capell-admin::reports.public_render_safety_description'))
        ->and($publicRenderSafetyReport['enabled'])->toBeFalse();
});

it('normalizes report visibility settings defensively', function (): void {
    $settings = NormalizeReportVisibilitySettingsAction::run([
        [
            'role_name' => 'editor',
            'reports' => [
                ['report_key' => 'core.public_render_safety', 'enabled' => false],
                ['report_key' => 'core.package_readiness'],
                ['report_key' => 'missing.report', 'enabled' => true],
                ['report_key' => '', 'enabled' => true],
                'not-a-report-row',
            ],
        ],
        [
            'role_name' => '',
            'reports' => [
                ['report_key' => 'core.publishing_readiness', 'enabled' => true],
            ],
        ],
        [
            'role_name' => 123,
            'reports' => [
                ['report_key' => 'core.demo_install_health', 'enabled' => true],
            ],
        ],
        [
            'role_name' => 'super_admin',
            'reports' => [
                ['report_key' => 'core.publishing_readiness', 'enabled' => true],
            ],
        ],
        [
            'role_name' => 'viewer',
            'reports' => 'not-a-report-list',
        ],
    ]);

    expect($settings)->toBe([
        'editor' => [
            'core.public_render_safety' => false,
            'core.package_readiness' => false,
        ],
        'super_admin' => [
            'core.publishing_readiness' => true,
        ],
    ]);
});

it('saves role scoped report visibility settings using stable report keys', function (): void {
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    $component = Livewire::test(SettingsPage::class)
        ->assertSuccessful();

    $state = $component->get('data.admin.report_visibility');
    expect($state)->toBeArray();

    foreach ($state as $roleIndex => $roleState) {
        if (($roleState['role_name'] ?? null) !== 'editor') {
            continue;
        }

        foreach ($roleState['reports'] as $reportIndex => $reportState) {
            if (($reportState['report_key'] ?? null) !== 'core.public_render_safety') {
                continue;
            }

            $state[$roleIndex]['reports'][$reportIndex]['enabled'] = false;
        }
    }

    $component
        ->fillForm([
            'admin' => [
                'report_visibility' => $state,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('settings', [
        'group' => 'admin',
        'name' => 'enabled_reports_by_role',
        'payload' => json_encode([
            'editor' => [
                'core.accessibility_readiness' => true,
                'core.publishing_readiness' => true,
                'core.demo_install_health' => true,
                'core.package_readiness' => true,
                'core.public_render_safety' => false,
            ],
            'super_admin' => [
                'core.accessibility_readiness' => true,
                'core.publishing_readiness' => true,
                'core.demo_install_health' => true,
                'core.package_readiness' => true,
                'core.public_render_safety' => true,
            ],
        ]),
    ]);
});

it('shows reports in navigation by default for eligible users', function (): void {
    test()->actingAsRole('editor');
    test()->authenticatedUser()->givePermissionTo('View:PublicRenderSafetyReport');

    expect(PublicRenderSafetyReport::shouldRegisterNavigation())->toBeTrue();
});

it('hides a disabled role report from navigation without blocking direct access', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_reports_by_role = [
        'editor' => [
            'core.public_render_safety' => false,
        ],
    ];
    $settings->save();

    test()->actingAsRole('editor');
    test()->authenticatedUser()->givePermissionTo('View:PublicRenderSafetyReport');

    expect(PublicRenderSafetyReport::shouldRegisterNavigation())->toBeFalse()
        ->and(PublicRenderSafetyReport::canAccess())->toBeTrue();
});

it('keeps shield page permissions isolated between registered report pages', function (): void {
    Permission::findOrCreate('View:PublishingReadinessReport', 'web');

    test()->actingAsRole('editor');
    test()->authenticatedUser()->givePermissionTo('View:PublishingReadinessReport');

    expect(PublishingReadinessReport::canAccess())->toBeTrue()
        ->and(PublicRenderSafetyReport::canAccess())->toBeFalse();

    test()->actingAsRole('editor');
    test()->authenticatedUser()->givePermissionTo('View:PublicRenderSafetyReport');

    expect(PublicRenderSafetyReport::canAccess())->toBeTrue()
        ->and(PublishingReadinessReport::canAccess())->toBeFalse();
});

it('keeps report page permissions isolated after mounting another report page', function (): void {
    Permission::findOrCreate('View:PublishingReadinessReport', 'web');

    test()->actingAsAdmin();

    Livewire::test(PublishingReadinessReport::class)
        ->assertSuccessful();

    test()->actingAsRole('editor');
    test()->authenticatedUser()->givePermissionTo('View:PublicRenderSafetyReport');

    expect(PublicRenderSafetyReport::canAccess())->toBeTrue();
});

it('omits disabled reports from built navigation groups only', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_reports_by_role = [
        'editor' => [
            'core.public_render_safety' => false,
        ],
    ];
    $settings->save();

    test()->actingAsRole('editor');
    test()->authenticatedUser()->givePermissionTo('View:PublicRenderSafetyReport');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();

    $reportsNavigationGroup = collect(Filament::getNavigation())
        ->first(fn (NavigationGroup $group): bool => $group->getLabel() === __('capell-admin::navigation.group_reports'));

    if ($reportsNavigationGroup instanceof NavigationGroup) {
        expect(collect($reportsNavigationGroup->getItems())->map->getLabel()->all())
            ->not->toContain(PublicRenderSafetyReport::getNavigationLabel());
    } else {
        expect($reportsNavigationGroup)->toBeNull();
    }
});

it('lets an extension bridge register a custom report page for settings and navigation', function (): void {
    resolve(AdminBridgeRegistrar::class)->report(new ReportDefinitionData(
        key: 'test.custom_report',
        label: 'Custom Report',
        description: 'Custom extension report.',
        package: 'capell-app/test-extension',
        category: 'Extension',
        pageClass: CustomReportPageForRegistry::class,
        navigationSort: 5,
    ));

    $settingsState = BuildReportVisibilityFormStateAction::run(AdminSettings::instance());

    expect(CapellAdmin::getReport('test.custom_report')?->pageClass)->toBe(CustomReportPageForRegistry::class)
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())->toContain(CustomReportPageForRegistry::class)
        ->and(collect($settingsState)->flatMap(fn (array $role): array => $role['reports'])->pluck('report_key')->all())
        ->toContain('test.custom_report');
});

it('resolves report page metadata and dispatches object actions', function (): void {
    CapellAdmin::registerReport(new ReportDefinitionData(
        key: 'test.plain_report',
        label: 'Plain Report',
        description: 'Plain report description.',
        package: 'capell-app/test-extension',
        category: 'Extension',
        pageClass: PlainReportPageForTest::class,
        defaultEnabled: false,
        navigationSort: 7,
    ));

    $page = resolve(PlainReportPageForTest::class);
    $snapshot = $page->reportSnapshot();

    expect(PlainReportPageForTest::getNavigationLabel())->toBe('Plain Report')
        ->and(PlainReportPageForTest::getNavigationGroup())->toBe(__('capell-admin::navigation.group_reports'))
        ->and(PlainReportPageForTest::getNavigationSort())->toBe(7)
        ->and(PlainReportPageForTest::shouldRegisterNavigation())->toBeFalse()
        ->and($page->getTitle())->toBe('Plain Report')
        ->and($page->getSubheading())->toBe('Plain report description.')
        ->and($snapshot->key)->toBe('test.plain_report')
        ->and($snapshot->isEmpty())->toBeFalse()
        ->and($snapshot->metrics[0]->label)->toBe('Open findings')
        ->and($snapshot->metrics[0]->value)->toBe(3)
        ->and($snapshot->metrics[0]->description)->toBe('Outstanding report findings.');
});

it('falls back safely when a report page has no registered definition', function (): void {
    $page = resolve(MissingReportPageForTest::class);

    expect(MissingReportPageForTest::getReportDefinition())->toBeNull()
        ->and(MissingReportPageForTest::getNavigationLabel())->toBe('Missing Report')
        ->and(MissingReportPageForTest::getNavigationSort())->toBeNull()
        ->and(MissingReportPageForTest::shouldRegisterNavigation())->toBeFalse()
        ->and(MissingReportPageForTest::isHiddenFromNavigationForCurrentUserRole())->toBeTrue()
        ->and($page->getSubheading())->toBeNull();
});

it('dispatches AsObject report actions from report pages', function (): void {
    $snapshot = resolve(PublicRenderSafetyReport::class)->reportSnapshot();

    expect($snapshot->key)->toBe('core.public_render_safety')
        ->and($snapshot->metrics)->not->toBeEmpty()
        ->and($snapshot->emptyState)->not->toBe('');
});

it('builds public render safety report metrics from recorded contract events', function (): void {
    PublicRenderContractEvent::query()->create([
        'result' => 'failed',
        'reason' => 'Public HTML contains a Capell internal marker.',
        'matched_marker' => 'frontendContextToken',
        'page_id' => 123,
    ]);

    $snapshot = BuildPublicRenderSafetyReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');

    expect($snapshot->key)->toBe('core.public_render_safety')
        ->and($metrics[__('capell-admin::reports.public_render_safety_metric_events')])->toBe(1)
        ->and($metrics[__('capell-admin::reports.public_render_safety_metric_failures')])->toBe(1)
        ->and($snapshot->findings)->toHaveCount(1)
        ->and($snapshot->findings[0]->recordLabel)->toContain('page #123');
});

it('builds package readiness scorecard metrics from registered manifests', function (): void {
    $snapshot = BuildPackageReadinessReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');

    expect($snapshot->key)->toBe('core.package_readiness')
        ->and($metrics[__('capell-admin::reports.package_readiness_metric_packages_checked')])->toBeGreaterThan(0);
});

it('treats frontend packages as render-safe until an attributed public render failure is recorded', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/frontend-package',
        surfaces: ['frontend'],
        overrides: [
            'contributes' => [
                [
                    'type' => 'asset',
                    'class' => null,
                    'surface' => 'frontend',
                ],
            ],
            'performance' => [
                'cacheSafety' => [
                    'cacheable' => true,
                    'sensitiveOutput' => false,
                ],
            ],
        ],
    ));

    $registry = new CapellPackageRegistry;
    $registry->fill(['vendor/frontend-package' => $manifest]);

    app()->instance(CapellPackageRegistry::class, $registry);

    $snapshot = BuildPackageReadinessReportAction::run();

    expect(collect($snapshot->findings)->pluck('recordLabel')->all())
        ->not->toContain('vendor/frontend-package');

    PublicRenderContractEvent::query()->create([
        'result' => 'failed',
        'package_name' => 'vendor/frontend-package',
        'reason' => 'Public HTML contains a Capell internal marker.',
        'matched_marker' => 'frontendContextToken',
    ]);

    $snapshot = BuildPackageReadinessReportAction::run();
    $finding = collect($snapshot->findings)
        ->firstWhere('recordLabel', 'vendor/frontend-package');

    expect($finding)->not->toBeNull()
        ->and($finding->description)->toContain('frontendContextToken');
});

it('treats content widget contributions as frontend packages without surface metadata', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/content-widget',
        surfaces: ['admin'],
        overrides: [
            'contributes' => [[
                'type' => 'content-widget',
                'class' => 'Vendor\\ContentWidget\\Widgets\\HeroWidget',
            ]],
            'performance' => [
                'cacheSafety' => [
                    'cacheable' => true,
                    'sensitiveOutput' => false,
                ],
            ],
        ],
    ));

    $registry = new CapellPackageRegistry;
    $registry->fill(['vendor/content-widget' => $manifest]);

    app()->instance(CapellPackageRegistry::class, $registry);

    $snapshot = BuildPackageReadinessReportAction::run();
    $descriptions = collect($snapshot->findings)
        ->where('recordLabel', 'vendor/content-widget')
        ->pluck('description')
        ->all();

    expect($descriptions)->toContain(__('capell-admin::reports.package_readiness_frontend_assets_missing'));
});
