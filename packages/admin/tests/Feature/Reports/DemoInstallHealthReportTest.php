<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses()->group('admin', 'reports');

it('builds demo install health metrics without critical findings for an install-shaped app', function (): void {
    demoInstallHealthSeedInstall();

    $snapshot = BuildDemoInstallHealthReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');
    $criticalFindings = collect($snapshot->findings)
        ->where('severity', ReportFindingSeverity::Critical);

    expect($snapshot->key)->toBe('core.demo_install_health')
        ->and($snapshot->metrics)->not->toBe([])
        ->and($metrics[__('capell-admin::reports.demo_install_health_metric_sites')])->toBe(1)
        ->and($metrics[__('capell-admin::reports.demo_install_health_metric_languages')])->toBe(1)
        ->and($metrics[__('capell-admin::reports.demo_install_health_metric_pages')])->toBe(1)
        ->and($metrics[__('capell-admin::reports.demo_install_health_metric_installed_packages')])->toBeGreaterThan(0)
        ->and($metrics[__('capell-admin::reports.demo_install_health_metric_settings_rows')])->toBeGreaterThan(0)
        ->and($metrics)->toHaveKey(__('capell-admin::reports.demo_install_health_metric_checks_passed'))
        ->and($criticalFindings->values()->all())->toBe([]);
});

it('reports a missing default theme record as a critical finding with remediation', function (): void {
    demoInstallHealthSeedInstall();

    Theme::query()->update(['default' => false]);

    $snapshot = BuildDemoInstallHealthReportAction::run();
    $finding = collect($snapshot->findings)
        ->firstWhere('title', 'Default theme and layout records');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(ReportFindingSeverity::Critical)
        ->and($finding->description)->toContain('No default theme')
        ->and($finding->description)->toContain('Rerun theme setup');
});

it('reports missing event sourcing tables as a critical finding with remediation', function (): void {
    demoInstallHealthSeedInstall();

    Schema::drop('page_revisions');
    resolve(RuntimeSchemaState::class)->flush();

    $snapshot = BuildDemoInstallHealthReportAction::run();
    $finding = collect($snapshot->findings)
        ->firstWhere('id', 'core.schema.required');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(ReportFindingSeverity::Critical)
        ->and($finding->description)->toContain('page_revisions')
        ->and($finding->remediation)->toContain('php artisan migrate');
});

it('reports invalid queue and cache configuration as critical operational findings', function (): void {
    demoInstallHealthSeedInstall();

    app()->instance(BuildDoctorReportAction::class, new class
    {
        public function handle(bool $installSummary = false, bool $includePackageDoctors = true): DoctorReportData
        {
            return new DoctorReportData('passed', collect());
        }
    });
    CapellCore::shouldReceive('getPackages')
        ->once()
        ->with(false)
        ->andReturn(collect());

    config([
        'queue.default' => 'missing',
        'queue.connections.missing' => null,
        'cache.default' => 'missing',
        'cache.stores.missing' => null,
    ]);

    $findings = collect(BuildDemoInstallHealthReportAction::run()->findings)->keyBy('id');

    expect($findings['core.queue.connection-configured']->severity)
        ->toBe(ReportFindingSeverity::Critical)
        ->and($findings['core.queue.connection-configured']->evidence)
        ->toMatchArray(['connection' => 'missing', 'driver' => null])
        ->and($findings['core.cache.store-configured']->severity)
        ->toBe(ReportFindingSeverity::Critical)
        ->and($findings['core.cache.store-configured']->evidence)
        ->toMatchArray(['store' => 'missing', 'driver' => null]);
});

it('reports missing event sourcing and settings data after installation is recorded', function (): void {
    demoInstallHealthSeedInstall();

    app()->instance(ResolveCapellInstallationStateAction::class, new class
    {
        public function handle(): CapellInstallationState
        {
            return CapellInstallationState::Installed;
        }
    });
    app()->instance(BuildDoctorReportAction::class, new class
    {
        public function handle(bool $installSummary = false, bool $includePackageDoctors = true): DoctorReportData
        {
            return new DoctorReportData('passed', collect());
        }
    });

    Schema::dropIfExists('stored_events');
    Schema::dropIfExists('settings');
    resolve(RuntimeSchemaState::class)->flush();

    $findings = collect(BuildDemoInstallHealthReportAction::run()->findings)->keyBy('id');

    expect($findings['core.schema.event-sourcing']->severity)
        ->toBe(ReportFindingSeverity::Critical)
        ->and($findings['core.schema.event-sourcing']->evidence)
        ->toMatchArray(['missing_tables' => ['stored_events']])
        ->and($findings['admin.settings.rows']->severity)
        ->toBe(ReportFindingSeverity::Warning);
});

it('returns the empty snapshot for a truly uninstalled app', function (): void {
    // Schema::disableForeignKeyConstraints() cannot actually take effect here:
    // every test runs inside LazilyRefreshDatabase's wrapping transaction, and
    // SQLite documents `PRAGMA foreign_keys` as a no-op while a transaction is
    // open (sqlite.org/pragma.html#pragma_foreign_keys). Enforcement silently
    // stays on, so `DROP TABLE` performs its implicit FK-cascade pass — and if
    // a still-existing table (e.g. `page_property_values`) holds a live
    // foreign key into a table THIS test already dropped (e.g. `pages`),
    // SQLite fails the next drop with "no such table" while resolving that
    // cascade. `dropTablesWithDependents()` sidesteps this by dropping every
    // table with a foreign key into `pages`/`sites` first, so no live FK into
    // either survives long enough to trigger the cascade.
    dropTablesWithDependents(['pages', 'sites', 'capell_extensions']);
    resolve(RuntimeSchemaState::class)->flush();

    $snapshot = BuildDemoInstallHealthReportAction::run();

    expect($snapshot->key)->toBe('core.demo_install_health')
        ->and($snapshot->isEmpty())->toBeTrue()
        ->and($snapshot->emptyState)->toBe(__('capell-admin::reports.empty_state_demo_install_health'));
});

function demoInstallHealthSeedInstall(): void
{
    CapellCore::forcePackageInstalled('capell-app/core');
    CapellExtension::query()->updateOrCreate(
        ['composer_name' => 'capell-app/core'],
        ['status' => ExtensionStatusEnum::Enabled, 'installed_at' => now()],
    );

    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->createOne(['default' => true]);
    $site = Site::factory()->withTranslations($language)->default()->theme($theme)->create([
        'language_id' => $language->getKey(),
    ]);
    $layout = Layout::factory()->site($site)->create(['default' => true]);

    $homePage = Page::factory()
        ->home()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Home'], slug: '/')
        ->create();

    SetupPageUrlsAction::run($homePage);

    $adminUser = User::factory()->createOne();
    $superAdminRole = Role::query()->firstOrCreate([
        'name' => config('capell.roles.super_admin', 'super_admin'),
        'guard_name' => 'web',
    ]);
    $adminUser->assignRole($superAdminRole);
}

/**
 * Drop $roots together with every currently-existing table that holds a live
 * foreign key into any of them — directly or transitively — dropping the
 * deepest dependents first so no surviving table ever references an
 * already-dropped one.
 *
 * This exists because `Schema::disableForeignKeyConstraints()` cannot be
 * trusted inside a test transaction on SQLite: see the call site for why.
 * Introspecting the schema (rather than hardcoding a table list) means a
 * future table with a foreign key into `pages`/`sites` is picked up
 * automatically instead of quietly reintroducing this failure.
 *
 * @param  list<string>  $roots
 */
function dropTablesWithDependents(array $roots): void
{
    $dependents = [];
    foreach (Schema::getTables() as $table) {
        foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
            $dependents[$foreignKey['foreign_table']][] = $table['name'];
        }
    }

    $visited = [];
    $drop = function (string $table) use (&$drop, &$visited, $dependents): void {
        if (isset($visited[$table])) {
            return;
        }

        $visited[$table] = true;
        foreach ($dependents[$table] ?? [] as $dependent) {
            $drop($dependent);
        }

        Schema::dropIfExists($table);
    };

    // A table can reference several roots at different depths. Reversing
    // breadth-first discovery is not a dependency order (page_term/terms).
    foreach ($roots as $root) {
        $drop($root);
    }
}
