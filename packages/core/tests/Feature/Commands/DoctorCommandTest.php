<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Command\Command;

function seedHealthyDoctorInstall(): void
{
    CapellCore::forcePackageInstalled('capell-app/core');
    CapellExtension::query()->updateOrCreate(
        ['composer_name' => 'capell-app/core'],
        ['status' => ExtensionStatusEnum::Enabled, 'installed_at' => now()],
    );

    $cssPath = resource_path('css/capell/frontend.css');
    File::ensureDirectoryExists(dirname($cssPath));
    File::put($cssPath, '/* test css */');

    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->createOne(['default' => true]);
    $site = Site::factory()->withTranslations($language)->default()->theme($theme)->create([
        'language_id' => $language->getKey(),
    ]);
    $layout = Layout::factory()->site($site)->create(['default' => true]);

    $homePage = Page::factory()
        ->home()
        ->published()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Home'], slug: '/')
        ->create();

    // Generate the public "/" page URL so the homepage health check (which now
    // exercises the real public resolver) can resolve a renderable home page,
    // matching a genuinely healthy install.
    SetupPageUrlsAction::run($homePage);

    $user = User::factory()->createOne();
    $role = Role::query()->firstOrCreate([
        'name' => config('capell.roles.super_admin', 'super_admin'),
        'guard_name' => 'web',
    ]);
    $user->assignRole($role);
}

afterEach(function (): void {
    File::delete(resource_path('css/capell/frontend.css'));
});

it('exits successfully when all checks pass', function (): void {
    seedHealthyDoctorInstall();

    artisanCommand('capell:doctor')
        ->assertExitCode(Command::SUCCESS);
});

it('reports required tables as present', function (): void {
    seedHealthyDoctorInstall();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Required tables exist')
        ->assertExitCode(Command::SUCCESS);
});

it('fails when required table is missing', function (): void {
    Schema::drop('sites');

    artisanCommand('capell:doctor')
        ->assertExitCode(Command::FAILURE);
});

it('reports seeded check failure when no records exist', function (): void {
    DB::table('sites')->delete();
    DB::table('languages')->delete();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Seed data is present')
        ->assertExitCode(Command::FAILURE);
});

it('reports and repairs page urls missing site domains', function (): void {
    seedHealthyDoctorInstall();

    $siteDomain = SiteDomain::query()->firstOrFail();
    $pageUrl = PageUrl::factory()
        ->recycle($siteDomain->site)
        ->recycle($siteDomain->language)
        ->create(['url' => '/orphaned']);

    $siteDomain->delete();

    $failedReport = BuildDoctorReportAction::run();
    $failedCheck = $failedReport->checks->firstWhere('label', 'Page URLs have site domains');

    expect($failedReport->passed())->toBeFalse()
        ->and($failedCheck?->passed)->toBeFalse()
        ->and($failedCheck?->message)->toContain((string) $pageUrl->getKey());

    artisanCommand('capell:doctor', ['--repair-page-url-domains' => true])
        ->expectsOutputToContain('Repaired 1 page URL site domain pair(s).')
        ->assertExitCode(Command::SUCCESS);

    $pageUrl->refresh()->unsetRelation('siteDomain');

    expect($pageUrl->full_url)->toContain('/orphaned');
});

it('reports morph map check', function (): void {
    seedHealthyDoctorInstall();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Morph map is complete')
        ->assertExitCode(Command::SUCCESS);
});

it('reports missing morph map aliases before public model serialization can drift', function (): void {
    seedHealthyDoctorInstall();

    $originalMorphMap = Relation::morphMap();
    Relation::morphMap([], false);

    try {
        $report = BuildDoctorReportAction::run();
        $check = $report->checks->firstWhere('label', 'Morph map is complete');

        expect($report->passed())->toBeFalse()
            ->and($check?->passed)->toBeFalse()
            ->and($check?->message)->toContain('Morph map missing aliases');
    } finally {
        Relation::morphMap($originalMorphMap, false);
    }
});

it('reports config files check', function (): void {
    seedHealthyDoctorInstall();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Config files')
        ->assertExitCode(Command::SUCCESS);
});

it('reports storage disks check', function (): void {
    seedHealthyDoctorInstall();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Storage disks are writable')
        ->assertExitCode(Command::SUCCESS);
});

it('reports configured storage disks that cannot be opened', function (): void {
    seedHealthyDoctorInstall();
    config([
        'capell.assets.disk' => 'broken-doctor-disk',
        'filesystems.disks.broken-doctor-disk' => [
            'driver' => 'not-a-real-driver',
        ],
    ]);

    $report = BuildDoctorReportAction::run();
    $check = $report->checks->firstWhere('label', 'Storage disks are writable');

    expect($report->passed())->toBeFalse()
        ->and($check?->passed)->toBeFalse()
        ->and($check?->message)->toContain('broken-doctor-disk')
        ->and($check?->remediation)->toBe('Check storage configuration and filesystem permissions.');
});

it('reports manifest v3 contract checks', function (): void {
    seedHealthyDoctorInstall();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Manifest v3 contracts')
        ->assertExitCode(Command::SUCCESS);
});

it('surfaces manifest contract errors from the extension audit action', function (): void {
    seedHealthyDoctorInstall();
    bindFakeAction(AuditExtensionContractsAction::class, [
        ['severity' => 'warning'],
        ['severity' => 'error'],
    ]);

    $report = BuildDoctorReportAction::run();
    $check = $report->checks->firstWhere('label', 'Manifest v3 contracts');

    expect($report->passed())->toBeFalse()
        ->and($check?->passed)->toBeFalse()
        ->and($check?->message)->toBe('1 manifest contract error(s).')
        ->and($check?->remediation)->toBe('Run php artisan capell:extension-audit.');
});

it('reports when no Capell packages are marked installed', function (): void {
    seedHealthyDoctorInstall();
    CapellCore::clearPackages();

    $report = BuildDoctorReportAction::run();
    $check = $report->checks->firstWhere('label', 'Installed Capell packages');

    expect($report->passed())->toBeFalse()
        ->and($check?->passed)->toBeFalse()
        ->and($check?->message)->toBe('No installed Capell packages were detected.');
});

it('reports when the users table is absent during admin access checks', function (): void {
    seedHealthyDoctorInstall();
    Schema::drop('users');

    $report = BuildDoctorReportAction::run();
    $check = $report->checks->firstWhere('label', 'Admin user access');

    expect($report->passed())->toBeFalse()
        ->and($check?->passed)->toBeFalse()
        ->and($check?->message)->toBe('The users table does not exist.');
});

it('does not require generated frontend tailwind css when no generator is registered', function (): void {
    seedHealthyDoctorInstall();
    File::delete(resource_path('css/capell/frontend.css'));

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('No frontend Tailwind generator is registered for this install.')
        ->assertExitCode(Command::SUCCESS);
});

it('requires generated frontend tailwind css when a generator is registered', function (): void {
    seedHealthyDoctorInstall();
    File::delete(resource_path('css/capell/frontend.css'));
    app()->bind('capell.tailwind.generator', fn (): stdClass => new stdClass);

    $report = BuildDoctorReportAction::run();
    $check = $report->checks->firstWhere('label', 'Generated frontend Tailwind CSS');

    expect($check?->remediation)
        ->toBe('Run php artisan capell:frontend-install, then npm run build if the application Vite bundle is not current.');

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('No generated Capell frontend CSS file was found.')
        ->expectsOutputToContain('capell:frontend-install')
        ->assertExitCode(Command::FAILURE);
});

it('outputs a stable json report shape', function (): void {
    seedHealthyDoctorInstall();

    $exitCode = Artisan::call('capell:doctor', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($payload)->toHaveKeys(['status', 'checks'])
        ->and($payload['checks'][0])->toHaveKeys(['label', 'passed', 'message', 'remediation']);
});

it('merges installed package doctor checks into the install summary', function (): void {
    seedHealthyDoctorInstall();
    CapellCore::forcePackageInstalled('capell-app/demo-kit', false);

    Artisan::command('capell:test-package-doctor {--json}', function (): int {
        $this->line(json_encode([
            'status' => 'passed',
            'checks' => [
                [
                    'id' => 'test-package.health',
                    'severity' => 'critical',
                    'label' => 'Package-owned doctor check',
                    'passed' => true,
                    'message' => 'Package doctor ran.',
                    'remediation' => null,
                    'evidence' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    });

    $packagePath = storage_path('framework/testing/test-package-doctor');
    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/capell.json', json_encode([
        'manifest-version' => 3,
        'name' => 'capell-app/test-package-doctor',
        'slug' => 'test-package-doctor',
        'displayName' => 'Test Package Doctor',
        'kind' => 'package',
        'capellApiVersion' => '^1.0',
        'version' => '1.x-dev',
        'description' => null,
        'product' => ['group' => 'Testing', 'tier' => 'free', 'bundle' => null],
        'surfaces' => [],
        'dependencies' => ['requires' => [], 'supports' => [], 'conflicts' => []],
        'providers' => ['metadata' => [], 'install' => [], 'runtime' => [], 'admin' => [], 'frontend' => []],
        'contributes' => [],
        'database' => ['migrations' => false, 'settings' => false, 'requiredTables' => []],
        'commands' => [
            'install' => null,
            'setup' => null,
            'demo' => null,
            'doctor' => 'capell:test-package-doctor',
        ],
        'settings' => [],
        'permissions' => [],
        'capabilities' => [],
        'performance' => [
            'frontendRenderBudgetMs' => 20,
            'adminQueryBudget' => 40,
            'cacheTags' => [],
            'cacheSafety' => [
                'cacheable' => false,
                'variesBy' => [],
                'sensitiveOutput' => false,
                'invalidationSources' => [],
                'queueInvalidation' => false,
            ],
        ],
        'healthChecks' => [],
        'commercial' => [
            'proposedLicense' => 'free',
            'requestedCertification' => 'first-party',
            'supportPolicy' => 'capell-first-party',
            'privateDocsRequested' => false,
        ],
        'marketplace' => ['summary' => null, 'screenshots' => [], 'categories' => []],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    CapellCore::registerPackage('capell-app/test-package-doctor', path: $packagePath);
    CapellCore::forcePackageInstalled('capell-app/test-package-doctor');

    $report = BuildDoctorReportAction::run(installSummary: true);
    $labels = $report->checks->pluck('label')->all();

    expect($labels)->toContain('Package-owned doctor check')
        ->and($report->passed())->toBeTrue();
});

it('can skip package doctor checks for installer health gates', function (): void {
    seedHealthyDoctorInstall();
    CapellCore::forcePackageInstalled('capell-app/demo-kit', false);

    Artisan::command('capell:test-failing-package-doctor {--json}', function (): int {
        $this->line(json_encode([
            'status' => 'failed',
            'checks' => [
                [
                    'id' => 'test-package.failure',
                    'severity' => 'critical',
                    'label' => 'Failing package-owned doctor check',
                    'passed' => false,
                    'message' => 'Package doctor failed.',
                    'remediation' => null,
                    'evidence' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return Command::FAILURE;
    });

    $packagePath = storage_path('framework/testing/test-failing-package-doctor');
    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/capell.json', json_encode([
        'manifest-version' => 3,
        'name' => 'capell-app/test-failing-package-doctor',
        'slug' => 'test-failing-package-doctor',
        'displayName' => 'Test Failing Package Doctor',
        'kind' => 'package',
        'capellApiVersion' => '^1.0',
        'version' => '1.x-dev',
        'description' => null,
        'product' => ['group' => 'Testing', 'tier' => 'free', 'bundle' => null],
        'surfaces' => [],
        'dependencies' => ['requires' => [], 'supports' => [], 'conflicts' => []],
        'providers' => ['metadata' => [], 'install' => [], 'runtime' => [], 'admin' => [], 'frontend' => []],
        'contributes' => [],
        'database' => ['migrations' => false, 'settings' => false, 'requiredTables' => []],
        'commands' => [
            'install' => null,
            'setup' => null,
            'demo' => null,
            'doctor' => 'capell:test-failing-package-doctor',
        ],
        'settings' => [],
        'permissions' => [],
        'capabilities' => [],
        'performance' => [
            'frontendRenderBudgetMs' => 20,
            'adminQueryBudget' => 40,
            'cacheTags' => [],
            'cacheSafety' => [
                'cacheable' => false,
                'variesBy' => [],
                'sensitiveOutput' => false,
                'invalidationSources' => [],
                'queueInvalidation' => false,
            ],
        ],
        'healthChecks' => [],
        'commercial' => [
            'proposedLicense' => 'free',
            'requestedCertification' => 'first-party',
            'supportPolicy' => 'capell-first-party',
            'privateDocsRequested' => false,
        ],
        'marketplace' => ['summary' => null, 'screenshots' => [], 'categories' => []],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    CapellCore::registerPackage('capell-app/test-failing-package-doctor', path: $packagePath);
    CapellCore::forcePackageInstalled('capell-app/test-failing-package-doctor');

    $report = BuildDoctorReportAction::run(installSummary: true, includePackageDoctors: false);
    $labels = $report->checks->pluck('label')->all();

    expect($labels)->not->toContain('Failing package-owned doctor check')
        ->and($report->passed())->toBeTrue();
});

it('reports invalid package doctor output without hiding the rest of the install summary', function (): void {
    seedHealthyDoctorInstall();

    Artisan::command('capell:test-invalid-json-doctor {--json}', function (): int {
        $this->line('not-json');

        return Command::SUCCESS;
    });

    Artisan::command('capell:test-invalid-shape-doctor {--json}', function (): int {
        $this->line(json_encode(['status' => 'passed'], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    });

    Artisan::command('capell:test-throwing-doctor {--json}', function (): int {
        throw new RuntimeException('Package doctor exploded.');
    });

    registerDoctorPackageForTest('capell-app/invalid-json-doctor', 'capell:test-invalid-json-doctor');
    registerDoctorPackageForTest('capell-app/invalid-shape-doctor', 'capell:test-invalid-shape-doctor');
    registerDoctorPackageForTest('capell-app/throwing-doctor', 'capell:test-throwing-doctor');

    $report = BuildDoctorReportAction::run(installSummary: true);
    $checks = $report->checks->keyBy('label');

    expect($report->passed())->toBeFalse()
        ->and($checks->get('Package doctor: capell:test-invalid-json-doctor')?->passed)->toBeFalse()
        ->and($checks->get('Package doctor: capell:test-invalid-json-doctor')?->message)->not->toBe('')
        ->and($checks->get('Package doctor: capell:test-invalid-shape-doctor')?->message)->toBe('Package doctor did not return a valid JSON check report.')
        ->and($checks->get('Package doctor: capell:test-throwing-doctor')?->message)->toBe('Package doctor exploded.');
});

it('reports degraded doctor states for admin access, config files, storage, and default content', function (): void {
    seedHealthyDoctorInstall();

    $configPath = config_path('capell-doctor-test.php');
    File::put($configPath, '<?php return [];');
    config()->set('capell.assets.disk', 'missing-assets-disk');

    DB::table('model_has_roles')->delete();
    Theme::query()->update(['default' => false]);
    Layout::query()->update(['default' => false]);

    $report = BuildDoctorReportAction::run();
    $checks = $report->checks->keyBy('label');

    expect($report->passed())->toBeFalse()
        ->and($checks->get('Admin user access')?->message)->toBe('Users exist but no role assignments were found.')
        ->and($checks->get('Config files')?->message)->toContain('capell-doctor-test.php')
        ->and($checks->get('Storage disks are writable')?->message)->toContain('some not configured')
        ->and($checks->get('Default theme and layout records')?->message)->toContain('No default theme')
        ->and($checks->get('Default theme and layout records')?->message)->toContain('No default layout');

    DB::table('users')->delete();

    $report = BuildDoctorReportAction::run();
    $checks = $report->checks->keyBy('label');

    expect($checks->get('Admin user access')?->message)->toBe('No users exist.');
});

it('does not recursively merge the core doctor into the install summary', function (): void {
    seedHealthyDoctorInstall();

    $report = BuildDoctorReportAction::run(installSummary: true);
    $labels = $report->checks->pluck('label')->all();

    expect(array_count_values($labels)['Required tables exist'] ?? 0)->toBe(1)
        ->and($report->passed())->toBeTrue();
});

it('fails when the homepage is missing', function (): void {
    seedHealthyDoctorInstall();
    Page::query()->delete();

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('Homepage route resolves')
        ->assertExitCode(Command::FAILURE);
});

it('fails the homepage check when the page resolves in the database but the public resolver rejects it', function (): void {
    seedHealthyDoctorInstall();

    // The page record still exists and a loose DB lookup would "resolve" it, but
    // the public resolver drops it because the page type is not accessible. This
    // is the exact gap (a green DB check masking a 404) the parity change closes.
    $homePage = Page::query()->firstOrFail();
    $homePage->blueprint->forceFill(['meta' => ['accessible' => false]])->save();

    $report = BuildDoctorReportAction::run();
    $check = $report->checks->firstWhere('label', 'Homepage route resolves');

    expect($report->passed())->toBeFalse()
        ->and($check?->passed)->toBeFalse()
        ->and($check?->message)->toContain('resolver returned no page');
});

function registerDoctorPackageForTest(string $packageName, string $doctorCommand): void
{
    $packagePath = storage_path('framework/testing/' . str($packageName)->after('/')->slug());

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/capell.json', json_encode(capellManifestV3Array(
        name: $packageName,
        surfaces: ['admin'],
        overrides: [
            'commands' => [
                'doctor' => $doctorCommand,
            ],
        ],
    ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    CapellCore::registerPackage($packageName, path: $packagePath);
    CapellCore::forcePackageInstalled($packageName);
}
