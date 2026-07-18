<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestInstallCommand;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Symfony\Component\Process\Process as SymfonyProcess;

require_once dirname(__DIR__, 5) . '/tests/Support/InstallFilesystemLock.php';

/**
 * PublishVendorMigrationsAction guards against silent vendor:publish failures by
 * verifying a `create_permission_tables` migration file exists. These tests mock
 * the console kernel so vendor:publish is a no-op — drop a stub in its place,
 * and remove it again on teardown so unrelated suites don't see a phantom
 * pending migration on disk.
 */
function stubPermissionMigration(): string
{
    $path = database_path('migrations/2025_01_01_000000_create_permission_tables.php');

    File::ensureDirectoryExists(dirname($path));
    File::put(
        $path,
        "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void {}\n    public function down(): void {}\n};\n",
    );

    return $path;
}

function writeRunInstallTestAdminPanelProvider(): void
{
    $path = app_path('Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin');
    }
}
PHP);
}

function bindRunInstallTestConsoleKernel(array $commands = ['capell:doctor' => true]): MockInterface
{
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->zeroOrMoreTimes()->andReturn($commands)->byDefault();
    $kernel->shouldReceive('call')->zeroOrMoreTimes()->andReturn(0)->byDefault();
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('')->byDefault();
    $kernel->shouldReceive('registerCommand')->zeroOrMoreTimes()->byDefault();
    app()->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    $process = Mockery::mock(SymfonyProcess::class);
    $process->shouldReceive('setTimeout')->withArgs(fn (?int $timeout): bool => in_array($timeout, [null, 120], true))->andReturnSelf();
    $process->shouldReceive('run')->andReturn(0);
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $process->shouldReceive('getExitCode')->andReturn(0);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->withArgs(fn (array $command): bool => in_array($command[2] ?? null, ['capell:admin-install', 'capell:doctor'], true))
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    return $kernel;
}

beforeEach(function (): void {
    acquireCapellInstallFilesystemLock();
});

afterEach(function (): void {
    $stub = database_path('migrations/2025_01_01_000000_create_permission_tables.php');
    if (File::exists($stub)) {
        File::delete($stub);
    }

    $panelProvider = app_path('Providers/Filament/AdminPanelProvider.php');
    if (File::exists($panelProvider)) {
        File::delete($panelProvider);
    }
});

afterEach(function (): void {
    releaseCapellInstallFilesystemLock();
});

function makeRunInstallInputData(
    array $packages = ['test'],
    ?int $userId = null,
    ?NewUserData $newUser = null,
    bool $demoContent = false,
    array $cachesToClear = [],
    bool $generateSitemap = false,
    bool $generateStaticSite = false,
    bool $freshInstall = false,
): InstallInputData {
    return new InstallInputData(
        siteUrl: 'https://example.com',
        packages: $packages,
        languages: ['en'],
        demoContent: $demoContent,
        cachesToClear: $cachesToClear,
        generateSitemap: $generateSitemap,
        generateStaticSite: $generateStaticSite,
        userId: $userId,
        newUser: $newUser,
        freshInstall: $freshInstall,
    );
}

it('reports numbered install steps before executing them', function (): void {
    $inputData = makeRunInstallInputData(packages: []);
    $stepLabels = [];
    $executedSteps = [];

    $reporter = new class($stepLabels) implements ProgressReporter
    {
        public function __construct(private array &$stepLabels) {}

        public function step(string $label): void
        {
            $this->stepLabels[] = $label;
        }

        public function report(string $line): void {}

        public function error(string $line): void {}
    };

    app()->instance(InstallStepExecutor::class, new class($executedSteps)
    {
        public function __construct(private array &$executedSteps) {}

        public function execute(string $stepKey, InstallRunState $state): InstallRunState
        {
            $this->executedSteps[] = $stepKey;

            return $state;
        }
    });

    RunInstallAction::run($inputData, $reporter);

    $steps = InstallPlan::steps($inputData);
    $firstStep = expectPresent($steps->first());
    $lastStep = expectPresent($steps->last());

    expect($stepLabels[0])->toBe('Starting installation…')
        ->and($stepLabels[1])->toBe(sprintf('[1/%d] %s', $steps->count(), $firstStep->label))
        ->and($stepLabels)->toContain(sprintf('[%d/%d] %s', $steps->count(), $steps->count(), $lastStep->label))
        ->and($executedSteps)->toBe($steps->pluck('key')->all());
});

it('uses the resolved install url as the app url while executing install steps', function (): void {
    config(['app.url' => 'https://example.com']);

    $inputData = new InstallInputData(
        siteUrl: 'https://capell-app.test',
        packages: [],
        languages: ['en'],
        demoContent: true,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );

    $appUrlDuringInstall = null;

    app()->instance(InstallStepExecutor::class, new class($appUrlDuringInstall)
    {
        public function __construct(private ?string &$appUrlDuringInstall) {}

        public function execute(string $stepKey, InstallRunState $state): InstallRunState
        {
            $this->appUrlDuringInstall = config('app.url');

            return $state;
        }
    });

    RunInstallAction::run($inputData, new NullProgressReporter);

    expect($appUrlDuringInstall)->toBe('https://capell-app.test');
});

it('creates a new user and marks the package installed during install with newUser', function (): void {
    Storage::fake();
    stubPermissionMigration();
    $fakeFilesystem = new FakeMigrationFilesystem;
    $this->app->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('test:install'));

    // Trigger LazilyRefreshDatabase before installing the kernel mock, so migrate:fresh
    // runs with the real kernel and the DB is fully initialised before we swap it.
    expect(User::query()->count())->toBe(0);

    // Clear the Artisan facade's cached kernel so subsequent Artisan::call() calls
    // use the mock rather than the previously resolved real kernel instance.
    bindRunInstallTestConsoleKernel();

    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../tests/fixtures/install-package'),
    );

    $inputData = makeRunInstallInputData(
        newUser: new NewUserData(
            name: 'Install User',
            email: 'install@example.com',
            password: 'secret123',
        ),
    );

    RunInstallAction::run($inputData, new NullProgressReporter);

    /** @var class-string<Model> $userModel */
    $userModel = config('auth.providers.users.model');
    expect($userModel::query()->where('email', 'install@example.com')->exists())->toBeTrue();
    expect(CapellCore::isPackageInstalled('test'))->toBeTrue();
});

it('uses an existing user by id and marks the package installed', function (): void {
    Storage::fake();
    stubPermissionMigration();
    $fakeFilesystem = new FakeMigrationFilesystem;
    $this->app->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('test:install'));

    $user = User::factory()->createOne();

    bindRunInstallTestConsoleKernel();

    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../tests/fixtures/install-package'),
    );

    $inputData = makeRunInstallInputData(userId: $user->id);

    RunInstallAction::run($inputData, new NullProgressReporter);

    expect(CapellCore::isPackageInstalled('test'))->toBeTrue();
});

it('clears capell data but preserves users for fresh installs', function (): void {
    Storage::fake();
    stubPermissionMigration();
    $fakeFilesystem = new FakeMigrationFilesystem;
    $this->app->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('test:install'));

    $user = User::factory()->createOne();
    Language::query()->create([
        'name' => 'Stale Language',
        'code' => 'stale',
    ]);

    $kernel = bindRunInstallTestConsoleKernel();
    $kernel->shouldReceive('call')
        ->once()
        ->with('db:wipe', ['--force' => true])
        ->andReturnUsing(function (): int {
            Language::query()->delete();

            return 0;
        });
    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../tests/fixtures/install-package'),
    );

    $inputData = makeRunInstallInputData(userId: $user->id, freshInstall: true);

    RunInstallAction::run($inputData, new NullProgressReporter);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
    expect(Schema::hasTable('languages') && Language::query()->where('code', 'stale')->exists())->toBeFalse();
    expect(CapellCore::isPackageInstalled('test'))->toBeTrue();
});

it('skips package installation when no packages are selected', function (): void {
    Storage::fake();
    stubPermissionMigration();
    $fakeFilesystem = new FakeMigrationFilesystem;
    $this->app->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('test:install'));

    $user = User::factory()->createOne();

    bindRunInstallTestConsoleKernel();

    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../tests/fixtures/install-package'),
    );

    $inputData = makeRunInstallInputData(packages: [], userId: $user->id);

    RunInstallAction::run($inputData, new NullProgressReporter);

    expect(CapellCore::isPackageInstalled('test'))->toBeFalse();
});

it('does not run core package install commands from the final core completion step', function (): void {
    TestInstallCommand::reset();

    Artisan::registerCommand(new TestInstallCommand('capell-core:install-test'));

    CapellCore::registerPackage(
        name: 'capell-app/capell',
        installCommand: 'capell-core:install-test',
    );

    RunInstallStepAction::run(
        InstallPlan::STEP_MARK_CORE_INSTALLED,
        makeRunInstallInputData(packages: ['capell-app/capell']),
        new NullProgressReporter,
    );

    expect(TestInstallCommand::$executionOrder)->not->toContain('capell-core:install-test');
});

it('fails the install when admin panel integration command fails', function (): void {
    Storage::fake();
    stubPermissionMigration();
    $fakeFilesystem = new FakeMigrationFilesystem;
    $this->app->instance(MigrationFilesystemInterface::class, $fakeFilesystem);
    writeRunInstallTestAdminPanelProvider();

    expect(User::query()->count())->toBe(0);

    $kernel = bindRunInstallTestConsoleKernel(['filament:install' => true, 'capell:doctor' => true]);
    $kernel->shouldReceive('call')->zeroOrMoreTimes()->andReturnUsing(
        fn (string $command): int => $command === 'capell:admin-setup' ? 1 : 0,
    );
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('No Filament panels found.');

    CapellCore::registerPackage(name: 'capell-app/admin');

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: ['capell-app/admin'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(
            name: 'Install User',
            email: 'install@example.com',
            password: 'secret123',
        ),
        seedDefaultData: false,
        integrateAdminPanel: true,
    );

    expect(fn (): mixed => RunInstallAction::run($inputData, new NullProgressReporter))
        ->toThrow(RuntimeException::class, "Command 'capell:admin-setup' failed with exit code 1.");
});

it('fails an install step when admin panel integration command fails', function (): void {
    CapellCore::registerPackage(name: 'capell-app/admin');

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->once()->with(
        'capell:admin-setup',
        Mockery::on(fn (array $arguments): bool => ($arguments['--integration-only'] ?? false) === true),
    )->andReturn(1);
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('No Filament panels found.');
    $kernel->shouldReceive('registerCommand')->zeroOrMoreTimes();
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: ['capell-app/admin'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: false,
        integrateAdminPanel: true,
    );

    expect(fn (): mixed => RunInstallStepAction::run(
        InstallPlan::STEP_INTEGRATE_ADMIN_PANEL,
        $inputData,
        new NullProgressReporter,
    ))->toThrow(RuntimeException::class, "Command 'capell:admin-setup' failed with exit code 1.");
});
