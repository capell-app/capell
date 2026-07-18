<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Actions\DemoPackageAction;
use Capell\Core\Contracts\AdminPermissionSynchronizer;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Tests\Fixtures\Filament\RuntimePermissionPage;
use Capell\Tests\Fixtures\Models\User;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process as SymfonyProcess;

function installStepExecutorProcessResult(bool $wasSuccessful, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = Mockery::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($wasSuccessful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

function expectInstallStepExecutorNpmProcessCommand(string $command, ProcessResult $result): void
{
    $pendingProcess = Mockery::mock();

    Process::shouldReceive('timeout')
        ->with(300)
        ->once()
        ->ordered()
        ->andReturn($pendingProcess);

    $pendingProcess->shouldReceive('run')
        ->with($command)
        ->once()
        ->ordered()
        ->andReturn($result);
}

function fakeInstallStepExecutorDemoProcess(): void
{
    DemoPackageAction::setProcessFactory(fn (array $command): object => new readonly class($command)
    {
        /** @param array<int, string> $command */
        public function __construct(private array $command) {}

        public function setTimeout(?float $timeout): self
        {
            return $this;
        }

        public function run(?callable $callback = null): int
        {
            $artisanIndex = array_search(base_path('artisan'), $this->command, true);
            assert(is_int($artisanIndex));

            $exitCode = Artisan::call($this->command[$artisanIndex + 1], $this->artisanArguments($artisanIndex + 2));

            if ($callback !== null) {
                $callback('out', Artisan::output());
            }

            return $exitCode;
        }

        public function isSuccessful(): bool
        {
            return true;
        }

        public function getExitCode(): int
        {
            return 0;
        }

        /** @return array<string, mixed> */
        private function artisanArguments(int $argumentOffset): array
        {
            return collect(array_slice($this->command, $argumentOffset))
                ->mapWithKeys(function (string $argument): array {
                    if (! str_starts_with($argument, '--')) {
                        return [];
                    }

                    if (! str_contains($argument, '=')) {
                        return [$argument => true];
                    }

                    [$name, $value] = explode('=', $argument, 2);

                    return [$name => $value];
                })
                ->all();
        }
    });
}

/** @param array<string, mixed> $additionalCommands */
function bindSuccessfulInstallDoctorCommand(array $additionalCommands = []): void
{
    app()->instance(ConsoleKernel::class, new readonly class($additionalCommands) implements ConsoleKernel
    {
        /** @param array<string, mixed> $additionalCommands */
        public function __construct(private array $additionalCommands) {}

        public function bootstrap(): void {}

        public function handle($input, $output = null): int
        {
            return 0;
        }

        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            return 0;
        }

        public function queue($command, array $parameters = []): PendingDispatch
        {
            throw new RuntimeException('The install flow should not queue commands.');
        }

        public function all(): array
        {
            return [
                'capell:doctor' => ['description' => 'Test doctor'],
                ...$this->additionalCommands,
            ];
        }

        public function output(): string
        {
            return 'Doctor OK';
        }

        public function terminate($input, $status): void {}
    });
    Facade::clearResolvedInstance(ConsoleKernel::class);

    $process = Mockery::mock(SymfonyProcess::class);
    $process->shouldReceive('setTimeout')->with(120)->andReturnSelf();
    $process->shouldReceive('run')->andReturnUsing(function (?callable $callback = null): int {
        if ($callback !== null) {
            $callback('out', "Doctor OK\n");
        }

        return 0;
    });
    $process->shouldReceive('getExitCode')->andReturn(0);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->withArgs(fn (array $command): bool => ($command[2] ?? null) === 'capell:doctor')
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
}

function installStepExecutorInputData(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

function installStepExecutorReporter(array &$lines): ProgressReporter
{
    return new class($lines) implements ProgressReporter
    {
        /**
         * @param  array<int, array{type: string, line: string}>  $lines
         */
        public function __construct(private array &$lines) {}

        public function step(string $label): void
        {
            $this->lines[] = ['type' => 'step', 'line' => $label];
        }

        public function report(string $line): void
        {
            $this->lines[] = ['type' => 'info', 'line' => $line];
        }

        public function error(string $line): void
        {
            $this->lines[] = ['type' => 'error', 'line' => $line];
        }
    };
}

beforeEach(function (): void {
    Facade::clearResolvedInstance(Factory::class);
    DemoPackageAction::resetProcessFactory();
    Process::spy();
});

afterEach(function (): void {
    Mockery::close();
    DemoPackageAction::resetProcessFactory();
    Facade::clearResolvedInstance('artisan');
    Facade::clearResolvedInstance(Factory::class);
});

it('fails the install step when npm cannot build frontend resources', function (): void {
    $errorMessage = "Cannot find module '@rollup/rollup-linux-arm64-gnu'.";

    expectInstallStepExecutorNpmProcessCommand(
        'npm run build',
        installStepExecutorProcessResult(false, '', $errorMessage),
    );
    expectInstallStepExecutorNpmProcessCommand(
        'npm install',
        installStepExecutorProcessResult(false, '', $errorMessage),
    );

    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    expect(fn (): InstallRunState => resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_REBUILD_RESOURCES,
        $state,
    ))->toThrow(RuntimeException::class, 'npm build failed: ' . $errorMessage);

    expect($lines)
        ->toContain(['type' => 'error', 'line' => '⚠ Frontend resources were not rebuilt.'])
        ->toContain(['type' => 'error', 'line' => 'The installer tried to run npm but the build failed. Log in to the server and run npm install, then npm run build.']);
});

it('reports successful npm rebuilds through the install step reporter', function (): void {
    expectInstallStepExecutorNpmProcessCommand(
        'npm run build',
        installStepExecutorProcessResult(true),
    );

    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_REBUILD_RESOURCES,
        $state,
    );

    expect($lines)
        ->toContain(['type' => 'step', 'line' => 'Rebuilding frontend resources…'])
        ->toContain(['type' => 'info', 'line' => '✓ Frontend resources rebuilt']);
});

it('fails the install step when the doctor summary finds release-blocking issues', function (): void {
    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    expect(fn (): InstallRunState => resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        $state,
    ))->toThrow(RuntimeException::class, 'Capell health summary failed.');

    expect($lines)
        ->toContain(['type' => 'error', 'line' => '⚠ Capell health summary found issues.'])
        ->toContain(['type' => 'error', 'line' => 'Installation stopped because the required health checks did not pass.']);
});

it('runs the final doctor summary in a fresh process even when the command is visible in the stale installer process', function (): void {
    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    app()->instance(ConsoleKernel::class, new class implements ConsoleKernel
    {
        public function bootstrap(): void {}

        public function handle($input, $output = null): int
        {
            return 0;
        }

        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            throw new RuntimeException('The in-process doctor command should not be called.');
        }

        public function queue($command, array $parameters = []): PendingDispatch
        {
            throw new RuntimeException('The install flow should not queue commands.');
        }

        public function all(): array
        {
            return ['capell:doctor' => new stdClass];
        }

        public function output(): string
        {
            return '';
        }

        public function terminate($input, $status): void {}
    });
    Facade::clearResolvedInstance(ConsoleKernel::class);

    $process = Mockery::mock(SymfonyProcess::class);
    $process->shouldReceive('setTimeout')
        ->with(120)
        ->once();
    $process->shouldReceive('run')
        ->once()
        ->with(Mockery::on('is_callable'))
        ->andReturnUsing(function (callable $callback): int {
            $callback('out', "Doctor OK\n");

            return 0;
        });
    $process->shouldReceive('getExitCode')
        ->once()
        ->andReturn(0);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->withArgs(fn (array $command, string $cwd, ?array $environment): bool => $command === [
            PHP_BINARY,
            'artisan',
            'capell:doctor',
            '--install-summary',
            '--skip-package-doctors',
            '--no-interaction',
        ] && $cwd === base_path() && is_array($environment))
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        $state,
    );

    expect($lines)
        ->toContain(['type' => 'step', 'line' => 'Running Capell health summary…'])
        ->toContain(['type' => 'info', 'line' => 'Doctor OK']);
});

it('reloads package runtime providers before final install permission sync', function (): void {
    $events = [];

    bindSuccessfulInstallDoctorCommand();

    app()->register(FilamentServiceProvider::class);
    app()->register(FilamentShieldServiceProvider::class);

    $panel = Panel::make()->id('admin')->path('admin')->default();
    Filament::registerPanel($panel);
    resolve(PanelRegistry::class)->defaultPanel = $panel;

    app()->instance(CapellPackageLoader::class, new class($events)
    {
        /**
         * @param  array<int, string>  $events
         */
        public function __construct(private array &$events) {}

        public function loadProviders(): void
        {
            $this->events[] = 'runtime-providers-reloaded';

            CapellAdmin::contributeToAdminSurface(
                AdminSurfaceContributionData::page(RuntimePermissionPage::class),
            );
        }
    });
    app()->instance(AdminPermissionSynchronizer::class, new class($events) implements AdminPermissionSynchronizer
    {
        /** @param array<int, string> $events */
        public function __construct(private array &$events) {}

        public function hasBootedPanel(): bool
        {
            return true;
        }

        public function syncForInstall(): void
        {
            $this->events[] = 'runtime-providers-reloaded';
            CapellAdmin::contributeToAdminSurface(
                AdminSurfaceContributionData::page(RuntimePermissionPage::class),
            );
            SyncCapellPermissionsAction::run(PermissionSyncMode::Install);
        }
    });

    $lines = [];
    $state = new InstallRunState(
        new InstallInputData(
            siteUrl: 'https://example.com',
            packages: ['capell-app/admin'],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        $state,
    );

    $permissionName = 'View:' . class_basename(RuntimePermissionPage::class);

    expect($events)->toBe(['runtime-providers-reloaded'])
        ->and(Permission::query()->where('name', $permissionName)->exists())->toBeTrue()
        ->and(Role::findByName('super_admin', 'web')->hasPermissionTo($permissionName, 'web'))->toBeTrue();
});

it('syncs admin permissions in a fresh process when no default Filament panel is booted', function (): void {
    app()->register(FilamentServiceProvider::class);
    app()->register(FilamentShieldServiceProvider::class);

    // Simulate a fresh skeleton install: the AdminPanelProvider was created on
    // disk during this process and never booted, so Filament has no default panel.
    resolve(PanelRegistry::class)->defaultPanel = null;

    // Composer installed Admin after this Artisan application started, so the
    // command is intentionally absent here and only visible to a fresh process.
    bindSuccessfulInstallDoctorCommand();

    $process = Mockery::mock(SymfonyProcess::class);
    $process->shouldReceive('setTimeout')->with(null)->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function (?callable $callback = null): int {
        if ($callback !== null) {
            $callback('out', 'Capell admin permissions synced.');
        }

        return 0;
    });
    $process->shouldReceive('getExitCode')->andReturn(0);

    $doctorProcess = Mockery::mock(SymfonyProcess::class);
    $doctorProcess->shouldReceive('setTimeout')->with(120)->andReturnSelf();
    $doctorProcess->shouldReceive('run')->once()->andReturnUsing(function (?callable $callback = null): int {
        if ($callback !== null) {
            $callback('out', 'Doctor OK');
        }

        return 0;
    });
    $doctorProcess->shouldReceive('getExitCode')->andReturn(0);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === [
                PHP_BINARY,
                '-d',
                'memory_limit=' . ini_get('memory_limit'),
                'artisan',
                'capell:admin-sync-permissions',
                '--mode=install',
                '--no-interaction',
            ]),
            Mockery::type('string'),
            Mockery::type('array'),
        )
        ->andReturn($process);
    $factory->shouldReceive('make')
        ->once()
        ->withArgs(fn (array $command): bool => in_array('capell:doctor', $command, true))
        ->andReturn($doctorProcess);

    app()->instance(ProcessFactoryInterface::class, $factory);

    $lines = [];
    $state = new InstallRunState(
        new InstallInputData(
            siteUrl: 'https://example.com',
            packages: ['capell-app/admin'],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        $state,
    );

    expect(collect($lines)->contains(fn (array $line): bool => $line['line'] === '✓ Admin permissions synced'))->toBeTrue();
});

it('skips package installation when no packages were selected', function (): void {
    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_INSTALL_PACKAGES,
        $state,
    );

    expect($lines)->toBeEmpty();
});

it('reports welcome route permission failures without failing the install step', function (): void {
    $sandboxPath = storage_path('framework/testing/install-step-welcome-route-' . uniqid());
    $routesPath = $sandboxPath . '/routes/web.php';
    $envPath = $sandboxPath . '/.env';

    File::ensureDirectoryExists(dirname($routesPath));
    File::put($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
PHP);
    File::ensureDirectoryExists($envPath);

    config([
        'capell.install.welcome_routes_web_path' => $routesPath,
        'capell.install.welcome_env_path' => $envPath,
    ]);

    try {
        $lines = [];
        $state = new InstallRunState(
            installStepExecutorInputData(),
            installStepExecutorReporter($lines),
        );

        expect(fn (): InstallRunState => resolve(InstallStepExecutor::class)->execute(
            InstallPlan::STEP_INSTALL_WELCOME_ROUTE,
            $state,
        ))->not()->toThrow(RuntimeException::class);

        expect($lines)
            ->toContain(['type' => 'error', 'line' => '⚠ Existing home route was not removed automatically.'])
            ->toContain(['type' => 'error', 'line' => 'Manual changes are required for .env or routes/web.php after install.']);
    } finally {
        File::deleteDirectory($sandboxPath);
        config([
            'capell.install.welcome_routes_web_path' => null,
            'capell.install.welcome_env_path' => null,
        ]);
    }
});

it('reports successful welcome route installation outcomes', function (): void {
    $sandboxPath = storage_path('framework/testing/install-step-welcome-route-success-' . uniqid());
    $routesPath = $sandboxPath . '/routes/web.php';
    $envPath = $sandboxPath . '/.env';

    File::ensureDirectoryExists(dirname($routesPath));
    File::put($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
PHP);
    File::put($envPath, "APP_NAME=Capell\n");

    config([
        'capell.install.welcome_routes_web_path' => $routesPath,
        'capell.install.welcome_env_path' => $envPath,
    ]);

    try {
        $lines = [];
        $state = new InstallRunState(
            installStepExecutorInputData(),
            installStepExecutorReporter($lines),
        );

        resolve(InstallStepExecutor::class)->execute(
            InstallPlan::STEP_INSTALL_WELCOME_ROUTE,
            $state,
        );

        expect($lines)
            ->toContain(['type' => 'step', 'line' => 'Removing existing home route…'])
            ->toContain(['type' => 'info', 'line' => '✓ Existing home route removed']);

        $lines = [];

        resolve(InstallStepExecutor::class)->execute(
            InstallPlan::STEP_INSTALL_WELCOME_ROUTE,
            new InstallRunState(installStepExecutorInputData(), installStepExecutorReporter($lines)),
        );

        expect($lines)
            ->toContain(['type' => 'info', 'line' => '✓ No removable home route found; skipped']);
    } finally {
        File::deleteDirectory($sandboxPath);
        config([
            'capell.install.welcome_routes_web_path' => null,
            'capell.install.welcome_env_path' => null,
        ]);
    }
});

it('executes selected package lifecycle steps with resolved installer context', function (): void {
    $calls = [];
    $commandSignature = '{--url=} {--user=} {--theme=} {--skip-panel-integration}';

    Artisan::command('codex:lifecycle-install ' . $commandSignature, function () use (&$calls): int {
        $calls[] = ['install', $this->option('url'), $this->option('user'), $this->option('theme'), $this->option('skip-panel-integration')];
        $this->line('install command ran');

        return 0;
    });
    Artisan::command('codex:lifecycle-setup ' . $commandSignature, function () use (&$calls): int {
        $calls[] = ['setup', $this->option('url'), $this->option('user'), $this->option('theme'), $this->option('skip-panel-integration')];
        $this->line('setup command ran');

        return 0;
    });
    Artisan::command('codex:lifecycle-demo ' . $commandSignature, function () use (&$calls): int {
        $calls[] = ['demo', $this->option('url'), $this->option('user'), $this->option('theme'), $this->option('skip-panel-integration')];
        $this->line('demo command ran');

        return 0;
    });
    Artisan::command('codex:lifecycle-after ' . $commandSignature, function () use (&$calls): int {
        $calls[] = ['after', $this->option('url'), $this->option('user'), $this->option('theme'), $this->option('skip-panel-integration')];
        $this->line('after command ran');

        return 0;
    });

    fakeInstallStepExecutorDemoProcess();

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/lifecycle-package',
        overrides: [
            'commands' => [
                'install' => 'codex:lifecycle-install',
                'installParams' => ['url', 'user', 'theme', 'skip-panel-integration'],
                'setup' => 'codex:lifecycle-setup',
                'setupParams' => ['url', 'user', 'theme', 'skip-panel-integration'],
                'demo' => 'codex:lifecycle-demo',
                'demoParams' => ['url', 'user', 'theme', 'skip-panel-integration'],
                'afterInstall' => 'codex:lifecycle-after',
                'afterInstallParams' => ['url', 'user', 'theme', 'skip-panel-integration'],
            ],
        ],
    )));

    $user = User::factory()->createOne();
    $lines = [];
    $state = new InstallRunState(
        new InstallInputData(
            siteUrl: 'https://example.com',
            packages: ['vendor/lifecycle-package'],
            languages: ['en'],
            demoContent: true,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            seedDefaultData: true,
            integrateAdminPanel: false,
            selectedThemeKey: 'editorial',
        ),
        installStepExecutorReporter($lines),
        (int) $user->getKey(),
    );

    $executor = resolve(InstallStepExecutor::class);
    $executor->execute(InstallPlan::packageInstallStepKey('vendor/lifecycle-package'), $state);
    $executor->execute(InstallPlan::packageSetupStepKey('vendor/lifecycle-package'), $state);
    $executor->execute(InstallPlan::packageDemoStepKey('vendor/lifecycle-package'), $state);
    $executor->execute(InstallPlan::packageAfterInstallStepKey('vendor/lifecycle-package'), $state);

    expect($calls)->toBe([
        ['install', 'https://example.com', (string) $user->getKey(), 'editorial', true],
        ['setup', 'https://example.com', (string) $user->getKey(), 'editorial', true],
        ['demo', 'https://example.com', (string) $user->getKey(), 'editorial', true],
        ['after', 'https://example.com', (string) $user->getKey(), 'editorial', true],
    ])
        ->and($lines)->toContain(['type' => 'step', 'line' => 'Installing vendor/lifecycle-package…'])
        ->and($lines)->toContain(['type' => 'step', 'line' => 'Setting up vendor/lifecycle-package…'])
        ->and($lines)->toContain(['type' => 'step', 'line' => 'Demo content for vendor/lifecycle-package…'])
        ->and($lines)->toContain(['type' => 'step', 'line' => 'Post-install vendor/lifecycle-package…']);
});

it('runs the database seeder with force when the seed database step executes', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')
        ->once()
        ->with('db:seed', ['--force' => true])
        ->andReturn(0);
    $kernel->shouldReceive('output')
        ->once()
        ->andReturn('Database\\Seeders\\DatabaseSeeder completed.');
    app()->instance(ConsoleKernel::class, $kernel);
    Facade::clearResolvedInstance('artisan');

    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_SEED_DATABASE,
        $state,
    );

    expect($lines)
        ->toContain(['type' => 'step', 'line' => 'Seeding database…'])
        ->toContain(['type' => 'info', 'line' => 'Database\\Seeders\\DatabaseSeeder completed.'])
        ->toContain(['type' => 'info', 'line' => '✓ Database seeded']);
});

it('integrates the admin panel with resolved schema and feature flags', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')
        ->once()
        ->with('capell:admin-setup', [
            '--integration-only' => true,
            '--panel' => 'admin',
            '--schemas' => 'resources/views=App\\Admin\\Schemas,resources/widgets=App\\Admin\\Widgets',
            '--no-colors' => false,
            '--no-widgets' => true,
            '--no-navigation' => false,
            '--force' => true,
        ])
        ->andReturn(0);
    $kernel->shouldReceive('output')
        ->once()
        ->andReturn('Admin panel integrated.');
    app()->instance(ConsoleKernel::class, $kernel);
    Facade::clearResolvedInstance('artisan');

    $lines = [];
    $state = new InstallRunState(
        new InstallInputData(
            siteUrl: 'https://example.com',
            packages: ['capell-app/admin'],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            seedDefaultData: false,
            integrateAdminPanel: true,
            adminPanel: 'admin',
            adminDiscoverSchemas: [
                ['in' => 'resources/views', 'for' => 'App\\Admin\\Schemas'],
                ['in' => 'resources/widgets', 'for' => 'App\\Admin\\Widgets'],
            ],
            adminAddColors: true,
            adminAddWidgets: false,
            adminAddNavigation: true,
        ),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_INTEGRATE_ADMIN_PANEL,
        $state,
    );

    expect($lines)
        ->toContain(['type' => 'step', 'line' => 'Integrating Capell Admin with Filament panel…'])
        ->toContain(['type' => 'info', 'line' => 'Admin panel integrated.']);
});

it('reports completion and rejects unknown install steps clearly', function (): void {
    $lines = [];
    $state = new InstallRunState(
        installStepExecutorInputData(),
        installStepExecutorReporter($lines),
    );

    resolve(InstallStepExecutor::class)->execute(
        InstallPlan::STEP_MARK_CORE_INSTALLED,
        $state,
    );

    expect($lines)->toContain(['type' => 'info', 'line' => '✓ Installation complete!'])
        ->and(CapellExtension::query()->where('composer_name', 'capell-app/core')->value('status'))
        ->toBe(ExtensionStatusEnum::Enabled);

    expect(fn (): InstallRunState => resolve(InstallStepExecutor::class)->execute('unknown-step', $state))
        ->toThrow(RuntimeException::class, 'Unknown install step: unknown-step');
});

it('publishes migrations for trusted core packages during install', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/core-marketplace-package-with-migrations');
    $migrationDirectory = $packagePath . '/database/migrations';
    $corePackagePath = base_path('tests/fixtures/core-package-with-migrations');
    $coreMigrationDirectory = $corePackagePath . '/database/migrations';
    $installCommandPackagePath = base_path('tests/fixtures/install-command-package-with-migrations');
    $installCommandMigrationDirectory = $installCommandPackagePath . '/database/migrations';

    File::ensureDirectoryExists($migrationDirectory);
    File::ensureDirectoryExists($coreMigrationDirectory);
    File::ensureDirectoryExists($installCommandMigrationDirectory);
    File::put($migrationDirectory . '/2026_05_10_190837_01_create_marketplace_instances_table.php', '<?php declare(strict_types=1);');
    File::put($coreMigrationDirectory . '/2026_05_10_190832_02_create_languages_table.php', '<?php declare(strict_types=1);');
    File::put($installCommandMigrationDirectory . '/create_install_command_records_table.php', '<?php declare(strict_types=1);');

    CapellCore::registerPackage('capell-app/marketplace', path: $packagePath);
    CapellCore::registerPackage('capell-app/capell', path: $corePackagePath);
    CapellCore::registerPackage('capell-app/install-command-package', path: $installCommandPackagePath, installCommand: 'capell:install-command-package-install');

    try {
        $lines = [];
        $state = new InstallRunState(
            installStepExecutorInputData(),
            installStepExecutorReporter($lines),
        );

        resolve(InstallStepExecutor::class)->execute(
            InstallPlan::STEP_PUBLISH_PACKAGE_MIGRATIONS,
            $state,
        );

        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_ends_with((string) $call[1], 'create_marketplace_instances_table.php'),
        ))->toBeTrue()
            ->and(collect($fakeFilesystem->calls)->contains(
                fn (array $call): bool => $call[0] === 'copy'
                    && str_ends_with((string) $call[1], 'create_languages_table.php'),
            ))->toBeFalse()
            ->and(collect($fakeFilesystem->calls)->contains(
                fn (array $call): bool => $call[0] === 'copy'
                    && str_ends_with((string) $call[1], 'create_install_command_records_table.php'),
            ))->toBeFalse();
    } finally {
        File::deleteDirectory($packagePath);
        File::deleteDirectory($corePackagePath);
        File::deleteDirectory($installCommandPackagePath);
    }
});
