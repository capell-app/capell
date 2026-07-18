<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Events\CapellInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeRunInstallAction;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestInstallCommand;
use Capell\Frontend\Http\Controllers\PageController;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process as SymfonyProcess;

require_once dirname(__DIR__, 5) . '/tests/Support/InstallFilesystemLock.php';

beforeEach(function (): void {
    ClearCachesAction::clearFake();
    RunInstallAction::clearFake();
    acquireCapellInstallFilesystemLock();
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();
});

afterEach(function (): void {
    ClearCachesAction::clearFake();
    RunInstallAction::clearFake();
    cleanupInstallTestApplicationFiles();
});

afterEach(function (): void {
    releaseCapellInstallFilesystemLock();
});

// Helper to setup the environment and return the fake filesystem
function setupInstallTest(array $packageNames = ['test']): array
{
    Storage::fake();
    CapellCore::clearPackages();
    bindDeveloperToolingInstallationState(false);
    $fakeFileManager = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);

    Artisan::registerCommand(new TestInstallCommand('test:install'));

    foreach ($packageNames as $packageName) {
        CapellCore::registerPackage(
            name: $packageName,
            path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
        );

    }

    if (in_array('capell-app/admin', $packageNames, true)) {
        writeStockInstallTestUserModel();
        writeStockInstallTestAdminPanelProvider();
    }

    return [$fakeFileManager];
}

function bindDeveloperToolingInstallationState(bool $installed): void
{
    app()->instance(DeveloperToolingInstallationState::class, new class($installed) extends DeveloperToolingInstallationState
    {
        public function __construct(private readonly bool $installed) {}

        public function isInstalled(): bool
        {
            return $this->installed;
        }
    });
}

function markInstallTestPackageAsFrontend(string $packageName): void
{
    CapellCore::getPackage($packageName)->scopes = [PackageScopeEnum::Frontend];
    writeStockInstallTestRoutes();
}

function writeStockInstallTestUserModel(): void
{
    $path = base_path('app/Models/User.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
}
PHP);
}

function writeStockInstallTestAdminPanelProvider(): void
{
    $path = base_path('app/Providers/Filament/AdminPanelProvider.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP);
}

function cleanupInstallTestApplicationFiles(): void
{
    foreach ([
        base_path('resources/css/app.css'),
        base_path('resources/js/app.js'),
    ] as $assetPath) {
        if (file_exists($assetPath)) {
            unlink($assetPath);
        }
    }

    foreach ([
        base_path('resources/css'),
        base_path('resources/js'),
        base_path('resources'),
    ] as $directoryPath) {
        if (is_dir($directoryPath) && count(scandir($directoryPath) ?? []) === 2) {
            rmdir($directoryPath);
        }
    }

    $path = base_path('app/Models/User.php');

    if (file_exists($path)) {
        unlink($path);
    }

    $modelsPath = base_path('app/Models');
    if (is_dir($modelsPath) && count(scandir($modelsPath) ?? []) === 2) {
        rmdir($modelsPath);
    }

    $adminPanelProviderPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    if (file_exists($adminPanelProviderPath)) {
        unlink($adminPanelProviderPath);
    }

    foreach ([
        base_path('app/Providers/Filament'),
        base_path('app/Providers'),
    ] as $directoryPath) {
        if (is_dir($directoryPath) && count(scandir($directoryPath) ?? []) === 2) {
            rmdir($directoryPath);
        }
    }

    $appPath = base_path('app');
    if (is_dir($appPath) && count(scandir($appPath) ?? []) === 2) {
        rmdir($appPath);
    }

    $routesPath = base_path('routes/web.php');
    if (file_exists($routesPath)) {
        unlink($routesPath);
    }

    $routesDirectory = base_path('routes');
    if (is_dir($routesDirectory) && count(scandir($routesDirectory) ?? []) === 2) {
        rmdir($routesDirectory);
    }
}

function writeStockInstallTestFrontendAssets(): void
{
    foreach ([
        base_path('resources/css/app.css') => 'body { color: inherit; }',
        base_path('resources/js/app.js') => 'console.log("capell");',
    ] as $assetPath => $contents) {
        if (! is_dir(dirname($assetPath))) {
            mkdir(dirname($assetPath), 0755, true);
        }

        file_put_contents($assetPath, $contents);
    }
}

function writeStockInstallTestRoutes(): void
{
    $path = base_path('routes/web.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
PHP);
}

function writeNamedWelcomeInstallTestRoutes(): void
{
    $path = base_path('routes/web.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', \Capell\Frontend\Http\Controllers\PageController::class)->name('home');
PHP);
}

function registerInstallTestFilamentInstallCommand(): void
{
    Artisan::registerCommand(new class extends Illuminate\Console\Command
    {
        protected $signature = 'filament:install {--panels}';

        public function handle(): int
        {
            writeStockInstallTestAdminPanelProvider();
            $this->line('Filament panel installed');

            return Command::SUCCESS;
        }
    });
}

// Helper to create a user
function createTestUser(): User
{
    return User::factory()->state(['name' => 'Test User', 'email' => 'test@example.com'])->create();
}

function dropUsersTableForInstallTest(): void
{
    Schema::disableForeignKeyConstraints();

    try {
        Schema::dropIfExists('users');
    } finally {
        Schema::enableForeignKeyConstraints();
    }
}

// Helper to bind a FakeRunInstallAction to the container and return it
function bindFakeRunInstallAction(): FakeRunInstallAction
{
    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    return $fake;
}

function installCommandFakeProcessResult(bool $wasSuccessful, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = Mockery::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($wasSuccessful);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);
    $result->shouldReceive('output')->andReturn($output);

    return $result;
}

function bindInstallCommandRemoveInstallerProcessFactory(?Closure $beforeMake = null): void
{
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();

    $process = Mockery::mock(SymfonyProcess::class);
    $process
        ->shouldReceive('setEnv')
        ->with(Mockery::on(fn (array $environment): bool => ($environment['GIT_CONFIG_KEY_0'] ?? null) === 'safe.directory'
            && ($environment['GIT_CONFIG_VALUE_0'] ?? null) === '*'))
        ->andReturnSelf();
    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturn(0);
    $process
        ->shouldReceive('getErrorOutput')
        ->andReturn('');
    $process
        ->shouldReceive('getOutput')
        ->andReturn('Package capell-app/installer removed');
    $process
        ->shouldReceive('isSuccessful')
        ->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === ['composer', 'remove', 'capell-app/installer', '--no-interaction', '--no-scripts']),
            Mockery::type('string'),
        )
        ->andReturnUsing(function () use ($beforeMake, $process): SymfonyProcess {
            $beforeMake?->__invoke();

            return $process;
        });

    app()->instance(ProcessFactoryInterface::class, $factory);
}

/** @param array<int, string> $packages */
function bindInstallCommandPreflightProcessFactory(
    bool $successful = true,
    string $output = 'Dry run ok',
    string $errorOutput = '',
    ?Closure $beforeRun = null,
    array $packages = ['capell-app/admin'],
): void {
    $process = Mockery::mock(SymfonyProcess::class);
    $process
        ->shouldReceive('setTimeout')
        ->with(600)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (?callable $callback = null) use ($beforeRun, $output, $successful): int {
            $beforeRun?->__invoke();

            if ($callback !== null && $output !== '') {
                $callback('out', $output);
            }

            return $successful ? 0 : 1;
        });
    $process->shouldReceive('isSuccessful')->andReturn($successful);
    $process->shouldReceive('getErrorOutput')->andReturn($errorOutput);
    $process->shouldReceive('getOutput')->andReturn($output);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === [
                'composer',
                'require',
                '--dry-run',
                '--no-interaction',
                '--prefer-dist',
                '--with-all-dependencies',
                ...array_map(
                    fn (string $package): string => app()->isLocal() ? $package . ':*' : $package,
                    $packages,
                ),
            ]),
            Mockery::type('string'),
            Mockery::type('array'),
        )
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

function bindInstallCommandFilamentPanelProcessFactory(bool $successful = true, string $output = 'Filament panel installed', string $errorOutput = ''): void
{
    $process = Mockery::mock(SymfonyProcess::class);
    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (?callable $callback = null) use ($successful, $output): int {
            if ($successful) {
                writeStockInstallTestAdminPanelProvider();
            }

            if ($callback !== null && $output !== '') {
                $callback('out', $output);
            }

            return $successful ? 0 : 1;
        });
    $process->shouldReceive('isSuccessful')->andReturn($successful);
    $process->shouldReceive('getErrorOutput')->andReturn($errorOutput);
    $process->shouldReceive('getOutput')->andReturn($output);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === [
                PHP_BINARY,
                'artisan',
                'filament:install',
                '--panels',
                '--no-interaction',
            ]),
            Mockery::type('string'),
            Mockery::type('array'),
        )
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

it('returns SUCCESS immediately when --no-side-effects is passed', function (): void {
    setupInstallTest();

    $exitCode = Artisan::call('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--no-side-effects' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('You are about to install Capell with side effects disabled.');
});

it('refuses --fresh when --production is set and does not invoke the install action', function (): void {
    setupInstallTest();
    $fake = bindFakeRunInstallAction();

    $exitCode = Artisan::call('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--production' => true,
        '--fresh' => 'force',
    ]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Refusing --fresh in --production mode')
        ->and($fake->callCount)->toBe(0);
});

it('prints the install plan and exits without running steps', function (): void {
    setupInstallTest();
    CapellCore::getPackage('test')->setupCommand = 'test:install';
    CapellCore::getPackage('test')->demoCommand = 'test:install';
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--demo' => true,
        '--fresh' => true,
        '--plan' => true,
        '--theme' => 'foundation',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Capell Install Plan')
        ->expectsOutputToContain('Set up')
        ->expectsOutputToContain('Demo content for')
        ->expectsOutputToContain('Run Capell health summary')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('renders install failures once and exits cleanly', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();
    $fake->throwable = new RuntimeException('Setup command failed because permissions are unavailable.');

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Capell installation failed.')
        ->expectsOutputToContain('Setup command failed because permissions are unavailable.')
        ->doesntExpectOutputToContain('Exception')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(1);
});

it('returns FAILURE when the specified user email does not exist', function (): void {
    setupInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'nonexistent@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('attempts filament installation non-interactively when admin is selected without an installed filament panel', function (): void {
    setupInstallTest(['capell-app/admin']);
    unlink(base_path('app/Providers/Filament/AdminPanelProvider.php'));
    bindInstallCommandFilamentPanelProcessFactory(false, '', 'filament:install is unavailable.');
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/admin',
        '--url' => 'https://example.test',
        '--name' => 'Test User',
        '--email' => 'test@example.com',
        '--password' => 'password',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Failed to scaffold Filament panel: filament:install is unavailable.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('exits when filament installation is declined for the admin package', function (): void {
    setupInstallTest(['capell-app/admin']);
    unlink(base_path('app/Providers/Filament/AdminPanelProvider.php'));
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/admin',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('The Capell admin package requires a Filament panel. Would you like to install Filament now?', 'no')
        ->expectsOutput('Filament must be installed before installing the Capell admin package.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('builds correct InstallInputData and delegates to RunInstallAction without cache selections', function (): void {
    setupInstallTest();
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->siteUrl)->toBe('https://example.test')
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->cachesToClear)->toBe([])
        ->and($fake->capturedInput->generateSitemap)->toBeFalse()
        ->and($fake->capturedInput->generateStaticSite)->toBeFalse()
        ->and($fake->capturedInput->demoContent)->toBeFalse();
});

it('dispatches CapellInstalled with the resolved spec path when --spec is given', function (): void {
    setupInstallTest();
    createTestUser();
    bindFakeRunInstallAction();

    Event::fake([CapellInstalled::class]);

    $specPath = tempnam(sys_get_temp_dir(), 'capell-core-spec-') . '.json';
    file_put_contents($specPath, json_encode([
        'site' => ['name' => 'Fixture Site'],
        'theme' => ['key' => 'default'],
        'pages' => [[
            'name' => 'Home',
            'slug' => 'home',
            'title' => 'Home',
            'pageType' => 'default',
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        artisanCommand('capell:install', [
            '--packages' => 'test',
            '--url' => 'https://example.test',
            '--user' => 'test@example.com',
            '--theme' => 'foundation',
            '--clear-cache' => true,
            '--spec' => $specPath,
        ])
            ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
            ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
            ->assertExitCode(Command::SUCCESS);
    } finally {
        @unlink($specPath);
    }

    Event::assertDispatched(
        CapellInstalled::class,
        fn (CapellInstalled $event): bool => $event->specPath === realpath($specPath) || $event->specPath === $specPath,
    );
});

it('does not dispatch CapellInstalled when --spec is omitted', function (): void {
    setupInstallTest();
    createTestUser();
    bindFakeRunInstallAction();

    Event::fake([CapellInstalled::class]);

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--theme' => 'foundation',
        '--clear-cache' => true,
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    Event::assertNotDispatched(CapellInstalled::class);
});

it('can remove the installer package at the end of an interactive install', function (): void {
    setupInstallTest(['test', 'capell-app/installer']);
    createTestUser();
    bindInstallCommandRemoveInstallerProcessFactory();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Delete the installer after installing?', 'yes')
        ->expectsOutput('Capell installer package removed successfully.')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1);
});

it('installs filament for the admin package before completing and removing the installer', function (): void {
    setupInstallTest(['capell-app/admin', 'capell-app/installer']);
    unlink(base_path('app/Providers/Filament/AdminPanelProvider.php'));
    registerInstallTestFilamentInstallCommand();
    createTestUser();
    $fake = bindFakeRunInstallAction();
    bindInstallCommandRemoveInstallerProcessFactory(function () use ($fake): void {
        expect($fake->callCount)->toBe(1);
    });

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/admin',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('The Capell admin package requires a Filament panel. Would you like to install Filament now?', 'yes')
        ->expectsOutput('Filament panel installed')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Delete the installer after installing?', 'yes')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsOutput('Capell installer package removed successfully.')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->packages)->toBe(['capell-app/admin'])
        ->and(file_exists(base_path('app/Providers/Filament/AdminPanelProvider.php')))->toBeTrue();
});

it('treats a selected but uninstalled admin package as an install-time composer package', function (): void {
    setupInstallTest(['capell-app/installer']);
    writeStockInstallTestUserModel();
    createTestUser();
    $fake = bindFakeRunInstallAction();
    $composerJson = file_get_contents(base_path('composer.json'));
    bindInstallCommandPreflightProcessFactory(beforeRun: function () use ($fake): void {
        expect($fake->callCount)->toBe(0);

        file_put_contents(base_path('composer.json'), '{"name":"capell/preflight-mutated"}');
    });

    $exitCode = Artisan::call('capell:install', [
        '--packages' => 'capell-app/admin',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'none',
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($fake->callCount)->toBe(1)
        ->and(file_get_contents(base_path('composer.json')))->toBe($composerJson)
        ->and(file_get_contents(base_path('app/Models/User.php')))->toContain(HasRoles::class)
        ->and($fake->capturedInput->packages)->toBe([])
        ->and($fake->capturedInput->extraPackages)->toBe(['capell-app/admin']);
});

it('fails before running the install when selected install-time packages cannot be composer required', function (): void {
    setupInstallTest(['capell-app/installer']);
    createTestUser();
    $fake = bindFakeRunInstallAction();
    bindInstallCommandPreflightProcessFactory(false, '', 'Package capell-app/admin was not found.');

    $exitCode = Artisan::call('capell:install', [
        '--packages' => 'capell-app/admin',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'none',
        '--no-interaction' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($fake->callCount)->toBe(0)
        ->and($output)->toContain('Capell installation failed.')
        ->and($output)->toContain('Selected packages cannot be installed via Composer [capell-app/admin]: Package capell-app/admin was not found.');
});

it('does not remove the installer package when the install fails', function (): void {
    setupInstallTest(['test', 'capell-app/installer']);
    createTestUser();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldNotReceive('make');

    app()->instance(ProcessFactoryInterface::class, $factory);
    $fake = bindFakeRunInstallAction();
    $fake->throwable = new RuntimeException('Package setup failed.');

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Delete the installer after installing?', 'yes')
        ->expectsOutputToContain('Capell installation failed.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(1);
});

it('can remove the installer package after a successful non-interactive install when requested', function (): void {
    setupInstallTest(['test', 'capell-app/installer']);
    createTestUser();
    bindInstallCommandRemoveInstallerProcessFactory();
    $fake = bindFakeRunInstallAction();

    $exitCode = Artisan::call('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--remove-installer' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($fake->callCount)->toBe(1);
});

it('emits a redacted install handoff and writes its machine-readable artifact', function (): void {
    setupInstallTest(['test']);
    createTestUser();
    bindFakeRunInstallAction();
    $relativePath = 'storage/framework/testing/capell-install-handoff.json';
    $absolutePath = base_path($relativePath);

    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }

    try {
        artisanCommand('capell:install', [
            '--packages' => 'test',
            '--url' => 'https://operator:secret@example.test/?token=private#fragment',
            '--user' => 'test@example.com',
            '--clear-cache' => true,
            '--theme' => 'foundation',
            '--handoff-json' => $relativePath,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Capell Install Handoff')
            ->expectsOutputToContain('Machine-readable install handoff written.')
            ->assertExitCode(Command::SUCCESS);
        $handoff = json_decode((string) file_get_contents($absolutePath), true, flags: JSON_THROW_ON_ERROR);

        expect($handoff['schemaVersion'])->toBe(1)
            ->and($handoff['status'])->toBe('completed')
            ->and($handoff['urls']['public'])->toBe('https://example.test/')
            ->and($handoff['publicImpact']['accountConnection'])->toBe('not_required')
            ->and($handoff['publicImpact']['telemetrySubmission'])->toBe('not_performed');
    } finally {
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }
});

it('leaves the installer package installed when removal is declined', function (): void {
    setupInstallTest(['test', 'capell-app/installer']);
    createTestUser();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldNotReceive('make');

    app()->instance(ProcessFactoryInterface::class, $factory);
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Delete the installer after installing?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1);
});

it('prompts for packages when no --packages option is given', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/marketplace',
    ]);
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/marketplace',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->freshInstall)->toBeFalse()
        ->and($fake->capturedInput->demoContent)->toBeFalse()
        ->and($fake->capturedInput->packages)->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/marketplace',
        ]);
});

it('allows packages to be skipped from the package checklist', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/marketplace',
    ]);
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe([
            'capell-app/admin',
        ]);
});

it('allows custom package selection to install no packages', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
    ]);
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--package-mode' => 'custom',
        '--packages' => '',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'none',
    ])
        ->expectsOutput('No packages selected.')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe([]);
});

it('allows related packages to be selected from the package checklist', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/admin-extra',
        'capell-app/frontend-extra',
    ]);
    CapellCore::getPackage('capell-app/admin-extra')->requirements = ['capell-app/admin'];
    CapellCore::getPackage('capell-app/frontend-extra')->requirements = ['capell-app/frontend'];
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [
            'capell-app/admin-extra',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe([
            'capell-app/admin',
            'capell-app/admin-extra',
        ]);
});

it('selects packages from the interactive package checklist', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'vendor/composer-installed-plugin',
    ]);
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [
            'vendor/composer-installed-plugin',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'vendor/composer-installed-plugin',
        ]);
});

it('preselects available packages in the interactive extra package checklist', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'vendor/composer-installed-plugin',
        'vendor/support-plugin',
    ]);
    CapellCore::getPackage('vendor/support-plugin')->visibility = 'support';
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [
            'vendor/composer-installed-plugin',
            'vendor/support-plugin',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'vendor/composer-installed-plugin',
            'vendor/support-plugin',
        ]);
});

it('logs package selection defaults when install package selection debugging is enabled', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'vendor/composer-installed-plugin',
    ]);
    createTestUser();
    bindFakeRunInstallAction();
    config()->set('capell.install.debug_package_selection', true);
    Log::spy();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [
            'vendor/composer-installed-plugin',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    Log::getFacadeRoot()->shouldHaveReceived('debug')
        ->with(
            'capell.install.package-selection: prompting for extra packages',
            Mockery::on(fn (array $context): bool => $context['options'] === ['vendor/composer-installed-plugin']
                && $context['default'] === ['vendor/composer-installed-plugin']
                && $context['interactive'] === true
                && $context['packages_option'] === null
                && $context['demo_option'] === false),
        )
        ->once();
});

it('preselects demo packages in the interactive extra package checklist for demo installs', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'vendor/demo-package',
    ]);
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--demo' => true,
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [
            'vendor/demo-package',
        ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toContain('vendor/demo-package');
});

it('allows demo packages to be deselected from the interactive extra package checklist', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'vendor/demo-package',
    ]);
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--demo' => true,
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->packages)->not->toContain('vendor/demo-package');
});

it('keeps theme packages out of the interactive package checklist', function (): void {
    setupInstallTest([
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/theme-corporate',
    ]);
    CapellCore::getPackage('capell-app/theme-corporate')->type = PackageTypeEnum::Theme;
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
    ])
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
        ])
        ->expectsQuestion('Which starter theme should be installed?', 'default')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toContain(
            'capell-app/admin',
            'capell-app/frontend',
        );
});

it('selects every registered package when --all-packages is given', function (): void {
    setupInstallTest([
        'capell-app/admin',
        'capell-app/frontend',
        'vendor/composer-installed-plugin',
    ]);
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();
    bindInstallCommandPreflightProcessFactory(packages: [
        'capell-app/marketplace',
        'capell-app/welcome-tour',
    ]);

    artisanCommand('capell:install', [
        '--all-packages' => true,
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'vendor/composer-installed-plugin',
        ]);
});

it('fails before installing when selected packages have unavailable requirements', function (): void {
    setupInstallTest(['packageA', 'packageB']);
    CapellCore::getPackage('packageB')->requirements = ['packageC'];
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'packageB',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsOutputToContain('Selected packages have missing requirements: packageB requires packageC.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('includes registered package requirements before selected packages', function (): void {
    setupInstallTest(['packageA', 'packageB']);
    CapellCore::getPackage('packageB')->requirements = ['packageA'];
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'packageB',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe(['packageA', 'packageB']);
});

it('allows selected packages when their requirements are also selected', function (): void {
    setupInstallTest(['packageA', 'packageB']);
    CapellCore::getPackage('packageB')->requirements = ['packageA'];
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'packageA,packageB',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->packages)->toBe(['packageA', 'packageB']);
});

it('prompts for the first user when the users table does not exist yet', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsQuestion('Name', 'New User')
        ->expectsQuestion('Email', 'new@example.test')
        ->expectsQuestion('Password', 'password')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('New User')
        ->and($fake->capturedInput->newUser?->email)->toBe('new@example.test')
        ->and($fake->capturedInput->userId)->toBeNull();
});

it('creates the first user from cli options without prompting', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci@example.test',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('CI Admin')
        ->and($fake->capturedInput->newUser?->email)->toBe('ci@example.test')
        ->and($fake->capturedInput->newUser?->password)->toBe('password123')
        ->and($fake->capturedInput->userId)->toBeNull();
});

it('creates the first user from installer admin defaults without prompting', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    config()->set('capell-installer.admin_user', [
        'name' => 'Configured Admin',
        'email' => 'configured-admin@example.test',
        'password' => 'password123',
    ]);
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--fresh' => true,
        '--demo' => true,
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('Configured Admin')
        ->and($fake->capturedInput->newUser?->email)->toBe('configured-admin@example.test')
        ->and($fake->capturedInput->newUser?->password)->toBe('password123')
        ->and($fake->capturedInput->demoContent)->toBeTrue()
        ->and($fake->capturedInput->freshInstall)->toBeTrue()
        ->and($fake->capturedInput->userId)->toBeNull();
});

it('creates the first user from core setup admin defaults when installer config is unavailable', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    config()->set('capell-installer.admin_user');
    config()->set('capell.install.admin_user', [
        'name' => 'Setup Admin',
        'email' => 'setup-admin@example.test',
        'password' => 'password123',
    ]);
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--fresh' => true,
        '--demo' => true,
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('Setup Admin')
        ->and($fake->capturedInput->newUser?->email)->toBe('setup-admin@example.test')
        ->and($fake->capturedInput->newUser?->password)->toBe('password123')
        ->and($fake->capturedInput->userId)->toBeNull();
});

it('adds example role users from cli options', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci@example.test',
        '--password' => 'password123',
        '--role-users' => true,
        '--role-user-password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->additionalUsers)->toHaveCount(2)
        ->and($fake->capturedInput->additionalUsers[0]->email)->toBe('super-admin@example.test')
        ->and($fake->capturedInput->additionalUsers[0]->roleName)->toBe('super_admin')
        ->and($fake->capturedInput->additionalUsers[1]->email)->toBe('editor@example.test')
        ->and($fake->capturedInput->additionalUsers[1]->roleName)->toBe('editor');
});

it('requires a password when creating role users non-interactively', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci@example.test',
        '--password' => 'password123',
        '--role-users' => true,
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--no-interaction' => true,
    ])
        ->expectsOutput('Pass --role-user-password=<password> when using --role-users non-interactively.')
        ->assertExitCode(Command::FAILURE);
});

it('allows creating a new admin user when users already exist', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsChoice(
            'Which admin user should we use?',
            '__create_admin_user__',
            [
                'existing' => 'Use an existing user',
                '__create_admin_user__' => 'Create a new admin user',
            ],
        )
        ->doesntExpectOutput('The selected user does not exist.')
        ->doesntExpectOutput('Select an existing user or create a new admin user.')
        ->doesntExpectOutput('Search for an existing admin user')
        ->doesntExpectOutput('Who should be the initial admin user?')
        ->expectsQuestion('Name', 'Admin User')
        ->expectsQuestion('Email', 'admin@example.test')
        ->expectsQuestion('Password', 'password')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('Admin User')
        ->and($fake->capturedInput->newUser?->email)->toBe('admin@example.test')
        ->and($fake->capturedInput->userId)->toBeNull();
});

it('forces creating a new admin user during a fresh install', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--fresh' => true,
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->doesntExpectOutput('Which admin user should we use?')
        ->doesntExpectOutput('Search for an existing admin user')
        ->expectsQuestion('Name', 'Fresh Admin')
        ->expectsQuestion('Email', 'fresh@example.test')
        ->expectsQuestion('Password', 'password')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('Fresh Admin')
        ->and($fake->capturedInput->newUser?->email)->toBe('fresh@example.test')
        ->and($fake->capturedInput->userId)->toBeNull()
        ->and($fake->capturedInput->freshInstall)->toBeTrue();
});

it('bypasses the fresh install confirmation when fresh is forced', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--fresh' => 'force',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--name' => 'Forced Fresh Admin',
        '--email' => 'forced-fresh@example.test',
        '--password' => 'password',
    ])
        ->doesntExpectOutput('Fresh install cancelled.')
        ->doesntExpectOutput('Warning: this will delete all your data. Are you sure?')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('Forced Fresh Admin')
        ->and($fake->capturedInput->newUser?->email)->toBe('forced-fresh@example.test')
        ->and($fake->capturedInput->userId)->toBeNull()
        ->and($fake->capturedInput->freshInstall)->toBeTrue();
});

it('stops immediately when explicit fresh install confirmation is rejected', function (): void {
    setupInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--fresh' => true,
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'no')
        ->expectsOutput('Fresh install cancelled.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('refuses destructive fresh installs in production mode before planning', function (): void {
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--production' => true,
        '--fresh' => true,
    ])
        ->expectsOutput('Refusing --fresh in --production mode (data-destructive). Drop --fresh or rerun without --production.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('forces creating a new admin user when reinstall confirmation enables a fresh install', function (): void {
    setupInstallTest();
    createTestUser();
    Site::factory()->createOne();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Capell is already installed. Refresh the database and reinstall?', 'yes')
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->doesntExpectOutput('Which admin user should we use?')
        ->doesntExpectOutput('Search for an existing admin user')
        ->expectsQuestion('Name', 'Reinstall Admin')
        ->expectsQuestion('Email', 'reinstall@example.test')
        ->expectsQuestion('Password', 'password')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->newUser?->name)->toBe('Reinstall Admin')
        ->and($fake->capturedInput->newUser?->email)->toBe('reinstall@example.test')
        ->and($fake->capturedInput->userId)->toBeNull()
        ->and($fake->capturedInput->freshInstall)->toBeTrue();
});

it('stops when reinstall confirmation enables a fresh install and destructive confirmation is rejected', function (): void {
    setupInstallTest();
    createTestUser();
    Site::factory()->createOne();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Capell is already installed. Refresh the database and reinstall?', 'yes')
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'no')
        ->expectsOutput('Fresh install cancelled.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('allows selecting an existing admin user from the search prompt', function (): void {
    setupInstallTest();
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsChoice(
            'Which admin user should we use?',
            'existing',
            [
                'existing' => 'Use an existing user',
                '__create_admin_user__' => 'Create a new admin user',
            ],
        )
        ->expectsSearch(
            'Search for an existing admin user',
            (string) $user->id,
            '',
            [
                $user->id => 'Test User <test@example.com>',
            ],
        )
        ->doesntExpectOutput('The selected user does not exist.')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->newUser)->toBeNull();
});

it('allows selecting an existing admin user from the search prompt after filtering by name', function (): void {
    setupInstallTest();
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsChoice(
            'Which admin user should we use?',
            'existing',
            [
                'existing' => 'Use an existing user',
                '__create_admin_user__' => 'Create a new admin user',
            ],
        )
        ->expectsSearch(
            'Search for an existing admin user',
            (string) $user->id,
            'Test',
            [
                $user->id => 'Test User <test@example.com>',
            ],
        )
        ->doesntExpectOutput('The selected user does not exist.')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->newUser)->toBeNull();
});

it('does not collect frontend asset paths during non-interactive installs', function (): void {
    setupInstallTest(['capell-app/frontend']);
    markInstallTestPackageAsFrontend('capell-app/frontend');
    writeStockInstallTestFrontendAssets();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci-assets@example.test',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->assets)->toBeNull();
});

it('preserves named home routes during frontend installs', function (): void {
    setupInstallTest(['capell-app/frontend']);
    markInstallTestPackageAsFrontend('capell-app/frontend');
    writeNamedWelcomeInstallTestRoutes();
    writeStockInstallTestFrontendAssets();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci-home-route@example.test',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    $routesContent = file_get_contents(base_path('routes/web.php'));
    $pageControllerClassPattern = '\\\\?' . preg_quote(PageController::class, '/');

    expect($fake->callCount)->toBe(1)
        ->and($routesContent)->toMatch('/Route::get\s*\(\s*[\'"]\/[\'"]\s*,\s*' . $pageControllerClassPattern . '::class\s*\)\s*->name\s*\(\s*[\'"]home[\'"]\s*\)/');
});

it('does not prompt for frontend asset paths during interactive installs', function (): void {
    setupInstallTest(['capell-app/frontend']);
    markInstallTestPackageAsFrontend('capell-app/frontend');
    writeStockInstallTestFrontendAssets();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci-assets@example.test',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Remove existing home route?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->assets)->toBeNull();
});

it('can run an npm build after installing a frontend package', function (): void {
    setupInstallTest(['capell-app/frontend']);
    markInstallTestPackageAsFrontend('capell-app/frontend');
    $fake = bindFakeRunInstallAction();

    $pendingProcess = Mockery::mock();

    Process::shouldReceive('timeout')
        ->with(300)
        ->once()
        ->andReturn($pendingProcess);

    $pendingProcess->shouldReceive('run')
        ->with('npm run build')
        ->once()
        ->andReturn(installCommandFakeProcessResult(true));

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci-build@example.test',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Remove existing home route?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'yes')
        ->expectsOutput('Running: npm run build')
        ->expectsOutput('Production build completed successfully.')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1);
});

it('fails instead of reporting a completed install when the requested npm build fails', function (): void {
    setupInstallTest(['capell-app/frontend']);
    markInstallTestPackageAsFrontend('capell-app/frontend');
    $fake = bindFakeRunInstallAction();

    $pendingProcess = Mockery::mock();

    Process::shouldReceive('timeout')
        ->with(300)
        ->once()
        ->andReturn($pendingProcess);

    $pendingProcess->shouldReceive('run')
        ->with('npm run build')
        ->once()
        ->andReturn(installCommandFakeProcessResult(false, '', 'Vite build failed.'));

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://example.test',
        '--name' => 'CI Admin',
        '--email' => 'ci-build-failure@example.test',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Remove existing home route?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'yes')
        ->expectsOutput('npm build failed.')
        ->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(1);
});

it('asks which caches to clear after the install has run', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
    ])
        ->expectsQuestion('Which starter theme should be installed?', 'default')
        ->expectsQuestion('Name', 'New User')
        ->expectsQuestion('Email', 'new@example.test')
        ->expectsQuestion('Password', 'password')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsQuestion('Which caches would you like to clear?', ['page', 'views'])
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->cachesToClear)->toBe([]);
});

it('does not ask for cache selection after a fresh seeded install', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--fresh' => true,
        '--seed' => true,
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--name' => 'Fresh Admin',
        '--email' => 'fresh@example.test',
        '--password' => 'password123',
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->freshInstall)->toBeTrue()
        ->and($fake->capturedInput->seedDatabase)->toBeTrue()
        ->and($fake->capturedInput->cachesToClear)->toBe([]);
});

it('asks for package selection during interactive fresh demo installs', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/demo-kit',
        'vendor/demo-package',
    ]);
    dropUsersTableForInstallTest();
    CapellCore::getPackage('capell-app/demo-kit')->demo = true;
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    config()->set('app.url', 'https://demo.example.test');
    config()->set('capell.install.debug', true);
    Log::spy();

    $capturedInput = null;

    RunInstallAction::shouldRun()
        ->once()
        ->withArgs(function ($inputData) use (&$capturedInput): bool {
            $capturedInput = $inputData;

            return true;
        });

    ClearCachesAction::shouldRun()
        ->once()
        ->withArgs(fn (array $cachesToClear): bool => $cachesToClear === ['all']);

    artisanCommand('capell:install', [
        '--fresh' => true,
        '--demo' => true,
    ])
        ->expectsOutput('You are about to install Capell with a fresh database refresh and demo content.')
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->expectsQuestion('What core Capell packages should be installed?', [
            'capell-app/admin',
            'capell-app/frontend',
        ])
        ->expectsQuestion('Would you like to install any extra extensions?', [
            'capell-app/demo-kit',
        ])
        ->assertExitCode(Command::SUCCESS);

    expect($capturedInput)->not()->toBeNull()
        ->and($capturedInput->freshInstall)->toBeTrue()
        ->and($capturedInput->demoContent)->toBeTrue()
        ->and($capturedInput->packages)->toContain(
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/demo-kit',
        )
        ->and($capturedInput->packages)->not->toContain('vendor/demo-package');
});

it('can orchestrate the fresh demo shortcut for every package without post-install prompts', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/content-sections',
        'capell-app/demo-kit',
        'vendor/demo-package',
    ]);
    dropUsersTableForInstallTest();
    CapellCore::getPackage('capell-app/demo-kit')->demo = true;
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    config()->set('app.url', 'https://demo.example.test');
    config()->set('capell.install.debug', true);
    Log::spy();
    $capturedInput = null;

    RunInstallAction::shouldRun()
        ->once()
        ->withArgs(function ($inputData) use (&$capturedInput): bool {
            $capturedInput = $inputData;

            return true;
        });

    ClearCachesAction::shouldRun()
        ->once()
        ->withArgs(fn (array $cachesToClear): bool => $cachesToClear === ['all']);

    artisanCommand('capell:install', [
        '--fresh' => true,
        '--demo' => true,
        '--package-mode' => 'all',
    ])
        ->expectsOutput('You are about to install Capell with a fresh database refresh and demo content.')
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->assertExitCode(Command::SUCCESS);

    expect($capturedInput)->not()->toBeNull()
        ->and($capturedInput->freshInstall)->toBeTrue()
        ->and($capturedInput->seedDatabase)->toBeFalse()
        ->and($capturedInput->demoContent)->toBeTrue()
        ->and($capturedInput->siteUrl)->toBe('https://demo.example.test')
        ->and($capturedInput->newUser)->not()->toBeNull()
        ->and($capturedInput->newUser->name)->toBe('Capell Admin')
        ->and($capturedInput->newUser->email)->toBe('admin@example.test')
        ->and($capturedInput->selectedThemeKey)->toBe('default')
        ->and($capturedInput->installDeveloperTooling)->toBeFalse()
        ->and($capturedInput->packages)->toContain(
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/content-sections',
            'capell-app/demo-kit',
            'vendor/demo-package',
        )
        ->and($capturedInput->cachesToClear)->toBe([]);

    Log::getFacadeRoot()->shouldHaveReceived('debug')
        ->with('capell.install: starting command', Mockery::type('array'))
        ->once();
    Log::getFacadeRoot()->shouldHaveReceived('debug')
        ->with('capell.install: using default site url', Mockery::on(
            fn (array $context): bool => $context['site_url'] === 'https://demo.example.test',
        ))
        ->once();
    Log::getFacadeRoot()->shouldHaveReceived('debug')
        ->with('capell.install: using default fresh demo admin user', Mockery::on(
            fn (array $context): bool => $context['email'] === 'admin@example.test',
        ))
        ->once();
    Log::getFacadeRoot()->shouldHaveReceived('debug')
        ->with('capell.install: running install orchestration', Mockery::type('array'))
        ->once();
    Log::getFacadeRoot()->shouldHaveReceived('debug')
        ->with('capell.install: finished command', Mockery::type('array'))
        ->once();
});

it('passes sitemap option to InstallInputData when requested', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--generate-sitemap' => true,
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->generateSitemap)->toBeTrue()
        ->and($fake->capturedInput->generateStaticSite)->toBeFalse();
});

it('passes developer tooling options to InstallInputData when requested', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--developer-tooling' => true,
    ])
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->installDeveloperTooling)->toBeTrue()
        ->and($fake->capturedInput->configureBoostDeveloperTooling)->toBeTrue();
});

it('can install developer tooling without running boost install', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--developer-tooling' => true,
        '--no-boost-install' => true,
    ])
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->installDeveloperTooling)->toBeTrue()
        ->and($fake->capturedInput->configureBoostDeveloperTooling)->toBeFalse();
});

it('does not rerun boost install when developer tooling is already installed', function (): void {
    setupInstallTest();
    bindDeveloperToolingInstallationState(true);
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->installDeveloperTooling)->toBeTrue()
        ->and($fake->capturedInput->configureBoostDeveloperTooling)->toBeFalse();
});

it('defaults interactive developer tooling to disabled', function (): void {
    setupInstallTest();
    dropUsersTableForInstallTest();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
    ])
        ->expectsQuestion('Which starter theme should be installed?', 'default')
        ->expectsQuestion('Name', 'New User')
        ->expectsQuestion('Email', 'new@example.test')
        ->expectsQuestion('Password', 'password')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsQuestion('Which caches would you like to clear?', ['page', 'views'])
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput)->not()->toBeNull()
        ->and($fake->capturedInput->installDeveloperTooling)->toBeFalse()
        ->and($fake->capturedInput->configureBoostDeveloperTooling)->toBeFalse();
});

it('defaults interactive cache clearing to every specific cache option', function (): void {
    expect(InstallCacheOptionCatalog::defaultKeys())->toBe([
        'page',
        'config',
        'views',
        'admin',
        'components',
        'widgets',
        'configurators',
        'filament-components',
    ])->not()->toContain('all');
});

it('passes demoContent=true to InstallInputData when --demo is given', function (): void {
    setupInstallTest();
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--demo' => true,
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->demoContent)->toBeTrue()
        ->and($fake->capturedInput->seedDefaultData)->toBeTrue()
        ->and($fake->capturedInput->demoLanguages)->toBe(['en', 'fr', 'de'])
        ->and($fake->capturedInput->demoSites)->toBe([
            config('app.name', 'Capell Application'),
            'Capell Knowledge',
            'Capell Services',
        ]);
});

it('puts english first in the default demo language list', function (): void {
    setupInstallTest();
    config()->set('app.locale', 'de');
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--demo' => true,
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->languages)->toBe(['en', 'de', 'fr'])
        ->and($fake->capturedInput->demoLanguages)->toBe(['en', 'de', 'fr']);
});

it('does not install demo content when --demo is omitted', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->demoContent)->toBeFalse()
        ->and($fake->capturedInput->demoLanguages)->toBeNull()
        ->and($fake->capturedInput->demoSites)->toBeNull();
});

it('includes available demo packages when demo content is requested', function (): void {
    setupInstallTest([
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'vendor/demo-package',
    ]);
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--demo' => true,
        '--packages' => 'capell-app/core,capell-app/admin,capell-app/frontend',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Add the Capell Filament Vite theme to AdminPanelProvider?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->demoContent)->toBeTrue()
        ->and($fake->capturedInput->packages)->toContain('vendor/demo-package');
});

it('seeds default data by default from the console install command', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->seedDefaultData)->toBeTrue();
});

it('allows default data to be skipped from the console install command', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--no-seed-default-data' => true,
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->seedDefaultData)->toBeFalse();
});

it('runs the application database seeder when requested from the console install command', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
        '--seed' => true,
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->seedDefaultData)->toBeTrue()
        ->and($fake->capturedInput->seedDatabase)->toBeTrue();
});

it('does not run the application database seeder by default from the console install command', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->seedDatabase)->toBeFalse();
});

it('passes freshInstall=true to InstallInputData when --fresh and --demo are given', function (): void {
    setupInstallTest();
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--fresh' => true,
        '--demo' => true,
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Warning: this will delete all your data. Are you sure?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->userId)->toBe($user->id)
        ->and($fake->capturedInput->demoContent)->toBeTrue()
        ->and($fake->capturedInput->demoLanguages)->toBe(['en', 'fr', 'de'])
        ->and($fake->capturedInput->demoSites)->toBe([
            config('app.name', 'Capell Application'),
            'Capell Knowledge',
            'Capell Services',
        ])
        ->and($fake->capturedInput->freshInstall)->toBeTrue();
});

it('returns FAILURE when no packages are selected', function (): void {
    Storage::fake();
    $fakeFileManager = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);

    $fake = bindFakeRunInstallAction();

    // No packages registered, so getSelectedPackages() returns empty
    artisanCommand('capell:install', [
        '--packages' => 'nonexistent-package',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])->assertExitCode(Command::FAILURE);

    expect($fake->callCount)->toBe(0);
});

it('passes multiple packages to InstallInputData', function (): void {
    setupInstallTest(['test1', 'test2']);
    $user = createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test1,test2',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
        '--theme' => 'foundation',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and($fake->capturedInput->packages)->toContain('test1', 'test2');
});

it('passes explicit selected theme key into InstallInputData', function (): void {
    setupInstallTest(['capell-app/theme-corporate']);
    CapellCore::getPackage('capell-app/theme-corporate')->type = PackageTypeEnum::Theme;
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/theme-corporate',
        '--theme' => 'corporate',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->selectedThemeKey)->toBe('corporate');
});

it('lets interactive installs choose no starter theme', function (): void {
    setupInstallTest();
    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--packages' => 'test',
        '--url' => 'https://example.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
    ])
        ->expectsQuestion('Which starter theme should be installed?', 'none')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->selectedThemeKey)->toBeNull();
});

it('uses install profile defaults when explicit install options are omitted', function (): void {
    setupInstallTest(['test', 'app/equidynamics-theme']);
    CapellCore::getPackage('app/equidynamics-theme')->type = PackageTypeEnum::Theme;
    CapellCore::getPackage('app/equidynamics-theme')->themeKey = 'equidynamics';
    config([
        'capell.install_profiles' => [
            'equidynamics' => [
                'packages' => ['test'],
                'theme' => 'equidynamics',
                'demo' => true,
                'languages' => ['en'],
                'sites' => ['Equidynamics'],
            ],
        ],
    ]);

    createTestUser();
    $fake = bindFakeRunInstallAction();

    artisanCommand('capell:install', [
        '--profile' => 'equidynamics',
        '--url' => 'https://equidynamics.test',
        '--user' => 'test@example.com',
        '--clear-cache' => true,
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->packages)->toContain('test', 'app/equidynamics-theme')
        ->and($fake->capturedInput->selectedThemeKey)->toBe('equidynamics')
        ->and($fake->capturedInput->demoContent)->toBeTrue()
        ->and($fake->capturedInput->demoLanguages)->toBe(['en'])
        ->and($fake->capturedInput->demoSites)->toBe(['Equidynamics']);
});
