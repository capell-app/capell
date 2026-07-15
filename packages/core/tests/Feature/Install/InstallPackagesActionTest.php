<?php

declare(strict_types=1);

use Capell\Core\Actions\DemoPackageAction;
use Capell\Core\Actions\Install\InstallPackagesAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Core\Tests\Feature\Commands\Fixtures\SharedTrackingCommand;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestDemoCommand;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestInstallCommand;
use Capell\Core\Tests\Feature\Commands\Fixtures\TrackingSetupCommand;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    CapellCore::clearPackages();
    LifecycleRecorderAction::reset();
    DemoPackageAction::resetProcessFactory();
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
});

afterEach(function (): void {
    LifecycleRecorderAction::reset();
    DemoPackageAction::resetProcessFactory();
});

function makeInstallInputData(array $packages, bool $demoContent = false, bool $seedDefaultData = true): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.com',
        packages: $packages,
        languages: ['en'],
        demoContent: $demoContent,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: $seedDefaultData,
    );
}

function bootstrapInstallPackagesTest(array $packageNames = ['test']): FakeMigrationFilesystem
{
    Storage::fake();
    CapellCore::clearPackages();
    TestInstallCommand::reset();
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('test:install'));

    foreach ($packageNames as $packageName) {
        CapellCore::registerPackage(
            name: $packageName,
            path: realpath(__DIR__ . '/../../../../tests/fixtures/install-package'),
        );
    }

    return $fakeFilesystem;
}

it('does nothing when packages list is empty', function (): void {
    $inputData = makeInstallInputData(packages: []);

    expect(fn (): mixed => InstallPackagesAction::run($inputData, null, new NullProgressReporter))
        ->not->toThrow(Throwable::class);
});

it('installs a registered package and marks it installed', function (): void {
    bootstrapInstallPackagesTest(['test']);
    $user = User::factory()->createOne();
    $inputData = makeInstallInputData(packages: ['test']);

    InstallPackagesAction::run($inputData, $user, new NullProgressReporter);

    expect(CapellCore::isPackageInstalled('test'))->toBeTrue();
});

it('boots the admin install lifecycle in a fresh process without requiring the fresh database option', function (): void {
    CapellCore::registerPackage(name: 'capell-app/admin', installCommand: 'capell:admin-install');

    $installPackage = Mockery::mock(InstallPackageAction::class);
    $installPackage->shouldReceive('handle')
        ->once()
        ->withArgs(fn (
            PackageData $package,
            array $arguments,
            ProgressReporter $reporter,
            bool $allowLegacyCommand,
            bool $freshLifecycleProcess,
        ): bool => $package->name === 'capell-app/admin'
            && $arguments === []
            && $allowLegacyCommand
            && $freshLifecycleProcess);
    app()->instance(InstallPackageAction::class, $installPackage);

    InstallPackagesAction::run(
        makeInstallInputData(packages: ['capell-app/admin'], seedDefaultData: false),
        null,
        new NullProgressReporter,
    );
});

it('skips packages that are not registered', function (): void {
    bootstrapInstallPackagesTest(['test']);
    $inputData = makeInstallInputData(packages: ['not-registered']);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(CapellCore::isPackageInstalled('test'))->toBeFalse();
});

it('installs multiple packages', function (): void {
    Storage::fake();
    TestInstallCommand::reset();
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('packageA:install'));
    Artisan::registerCommand(new TestInstallCommand('packageB:install'));
    Artisan::registerCommand(new TestInstallCommand('packageC:install'));
    Artisan::registerCommand(new TrackingSetupCommand('packageA:setup'));
    Artisan::registerCommand(new TrackingSetupCommand('packageB:setup'));
    Artisan::registerCommand(new TrackingSetupCommand('packageC:setup'));

    CapellCore::registerPackage(name: 'packageA', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-a'));
    CapellCore::registerPackage(name: 'packageB', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-b'));
    CapellCore::registerPackage(name: 'packageC', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-c'));

    $inputData = makeInstallInputData(packages: ['packageA', 'packageB', 'packageC']);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TestInstallCommand::$executionOrder)
        ->toContain('packageA:install', 'packageB:install', 'packageC:install');
});

it('installs packages in dependency order', function (): void {
    Storage::fake();
    TestInstallCommand::reset();
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('packageA:install'));
    Artisan::registerCommand(new TestInstallCommand('packageB:install'));
    Artisan::registerCommand(new TestInstallCommand('packageC:install'));
    Artisan::registerCommand(new TrackingSetupCommand('packageA:setup'));
    Artisan::registerCommand(new TrackingSetupCommand('packageB:setup'));
    Artisan::registerCommand(new TrackingSetupCommand('packageC:setup'));

    // Register in reverse dependency order to prove sorting works
    CapellCore::registerPackage(name: 'packageC', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-c'));
    CapellCore::registerPackage(name: 'packageB', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-b'));
    CapellCore::registerPackage(name: 'packageA', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-a'));

    $inputData = makeInstallInputData(packages: ['packageA', 'packageB', 'packageC']);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TestInstallCommand::$executionOrder)->toBe([
        'packageA:install',
        'packageB:install',
        'packageC:install',
    ]);
});

it('runs all install commands before any setup command so setup can depend on installed packages', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    SharedTrackingCommand::reset();

    Artisan::registerCommand(new SharedTrackingCommand('packageA:install'));
    Artisan::registerCommand(new SharedTrackingCommand('packageB:install'));
    Artisan::registerCommand(new SharedTrackingCommand('packageA:setup'));
    Artisan::registerCommand(new SharedTrackingCommand('packageB:setup'));

    CapellCore::registerPackage(name: 'packageA', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-a'));
    CapellCore::registerPackage(name: 'packageB', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-b'));

    $inputData = makeInstallInputData(packages: ['packageB'], seedDefaultData: true);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(SharedTrackingCommand::$executionOrder)->toBe([
        'packageA:install',
        'packageB:install',
        'packageA:setup',
        'packageB:setup',
    ]);
});

it('installs registered package requirements before selected packages', function (): void {
    Storage::fake();
    TestInstallCommand::reset();
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    Artisan::registerCommand(new TestInstallCommand('packageA:install'));
    Artisan::registerCommand(new TestInstallCommand('packageB:install'));
    Artisan::registerCommand(new TestInstallCommand('packageC:install'));

    CapellCore::registerPackage(name: 'packageC', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-c'));
    CapellCore::registerPackage(name: 'packageB', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-b'));
    CapellCore::registerPackage(name: 'packageA', path: realpath(__DIR__ . '/../../../../../tests/fixtures/package-a'));

    $inputData = makeInstallInputData(packages: ['packageC'], seedDefaultData: false);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TestInstallCommand::$executionOrder)->toBe([
        'packageA:install',
        'packageB:install',
        'packageC:install',
    ]);
});

it('runs declared lifecycles for composer-available distribution packages without creating extension records', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    TestInstallCommand::reset();
    TrackingSetupCommand::reset();

    Artisan::registerCommand(new TestInstallCommand('capell-admin:install-test'));
    Artisan::registerCommand(new TrackingSetupCommand('capell-admin:setup-test'));

    CapellCore::registerPackage(
        name: 'capell-app/admin',
        path: makeInstallComposerPackageFixture('capell-app/admin'),
        setupCommand: 'capell-admin:setup-test',
        installCommand: 'capell-admin:install-test',
    );
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/fresh-install-addon',
        surfaces: ['admin'],
        overrides: [
            'dependencies' => [
                'requires' => ['capell-app/admin'],
                'supports' => [],
                'conflicts' => [],
            ],
        ],
    )));

    $inputData = makeInstallInputData(packages: ['capell-app/admin', 'vendor/fresh-install-addon']);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeTrue()
        ->and(CapellCore::isPackageInstalled('vendor/fresh-install-addon'))->toBeTrue()
        ->and(CapellExtension::query()->where('composer_name', 'capell-app/admin')->exists())->toBeFalse()
        ->and(CapellExtension::query()->where('composer_name', 'vendor/fresh-install-addon')->exists())->toBeTrue()
        ->and(TestInstallCommand::$executionOrder)->toContain('capell-admin:install-test')
        ->and(TrackingSetupCommand::$executionOrder)->toContain('capell-admin:setup-test');
});

// ─── seedDefaultData ──────────────────────────────────────────────────────────

it('runs the setup command when seedDefaultData is true', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new TrackingSetupCommand('test:setup'));
    TrackingSetupCommand::reset();

    CapellCore::registerPackage(name: 'test', setupCommand: 'test:setup');

    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: true);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TrackingSetupCommand::$executionOrder)->toContain('test:setup');
});

it('runs setup lifecycle actions even when no setup command is declared', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->setupAction = LifecycleRecorderAction::class;
    CapellCore::getPackage('test')->setupParams = ['url'];

    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: true);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(LifecycleRecorderAction::$calls)->toBe([
        [
            'package' => 'test',
            'arguments' => ['--url' => 'https://example.com'],
        ],
    ]);
});

it('uses Capell as the default site name when installing into a stock Laravel app', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    config()->set('app.name', 'Laravel');

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->setupAction = LifecycleRecorderAction::class;
    CapellCore::getPackage('test')->setupParams = ['sites'];

    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: true);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(LifecycleRecorderAction::$calls)->toBe([
        [
            'package' => 'test',
            'arguments' => ['--sites' => ['Capell']],
        ],
    ]);
});

it('keeps custom app names as the default install site name', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    config()->set('app.name', 'Acme Publishing');

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->setupAction = LifecycleRecorderAction::class;
    CapellCore::getPackage('test')->setupParams = ['sites'];

    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: true);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(LifecycleRecorderAction::$calls)->toBe([
        [
            'package' => 'test',
            'arguments' => ['--sites' => ['Acme Publishing']],
        ],
    ]);
});

it('passes skip permission sync into package setup commands during full install', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'test:setup {--skip-permission-sync}';

        public function handle(): int
        {
            return $this->option('skip-permission-sync') === true
                ? self::SUCCESS
                : self::FAILURE;
        }
    });

    CapellCore::registerPackage(
        name: 'test',
        setupCommand: 'test:setup',
        setupParams: ['skip-permission-sync'],
    );

    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: true);

    expect(fn (): mixed => InstallPackagesAction::run($inputData, null, new NullProgressReporter))
        ->not->toThrow(Throwable::class);
});

it('passes selected theme into package setup commands during full install', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'test:setup {--theme=}';

        public function handle(): int
        {
            config(['test.selected_theme' => $this->option('theme')]);

            return self::SUCCESS;
        }
    });

    CapellCore::registerPackage(
        name: 'test',
        setupCommand: 'test:setup',
        setupParams: ['theme'],
    );

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: ['test'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
        selectedThemeKey: 'corporate',
    );

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(config('test.selected_theme'))->toBe('corporate');
});

it('skips the setup command when seedDefaultData is false', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new TrackingSetupCommand('test:setup'));
    TrackingSetupCommand::reset();

    CapellCore::registerPackage(name: 'test', setupCommand: 'test:setup');

    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: false);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TrackingSetupCommand::$executionOrder)->not->toContain('test:setup');
});

it('runs after install lifecycle actions even when no after install command is declared', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->afterInstallAction = LifecycleRecorderAction::class;
    CapellCore::getPackage('test')->afterInstallParams = ['url'];

    $inputData = makeInstallInputData(packages: ['test']);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(LifecycleRecorderAction::$calls)->toBe([
        [
            'package' => 'test',
            'arguments' => ['--url' => 'https://example.com'],
        ],
    ]);
});

it('runs demo commands when demo content is enabled', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new TestDemoCommand);
    TestDemoCommand::reset();

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->demoCommand = 'test:demo';
    CapellCore::getPackage('test')->demoParams = ['url'];

    $user = User::factory()->createOne();
    $inputData = new InstallInputData(
        siteUrl: 'https://demo.example.com',
        packages: ['test'],
        languages: ['en'],
        demoContent: true,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        demoSites: ['Demo Site'],
        demoLanguages: ['en'],
        seedDefaultData: true,
    );

    InstallPackagesAction::run($inputData, $user, new NullProgressReporter);

    expect(TestDemoCommand::$executionOrder)->toBe(['test:demo'])
        ->and(TestDemoCommand::$receivedOptions)->toMatchArray([
            'url' => 'https://demo.example.com',
        ]);
});

it('does not run matching setup and demo commands twice when demo content is enabled', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new TestDemoCommand);
    TestDemoCommand::reset();

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->setupCommand = 'test:demo';
    CapellCore::getPackage('test')->setupParams = ['url'];
    CapellCore::getPackage('test')->demoCommand = 'test:demo';
    CapellCore::getPackage('test')->demoParams = ['url'];

    $inputData = new InstallInputData(
        siteUrl: 'https://demo.example.com',
        packages: ['test'],
        languages: ['en'],
        demoContent: true,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
    );

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TestDemoCommand::$executionOrder)->toBe(['test:demo']);
});

it('skips demo commands when demo content is disabled', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new TestDemoCommand);
    TestDemoCommand::reset();

    CapellCore::registerPackage(name: 'test');
    CapellCore::getPackage('test')->demoCommand = 'test:demo';
    CapellCore::getPackage('test')->demoParams = ['url'];

    $inputData = makeInstallInputData(packages: ['test'], demoContent: false);

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(TestDemoCommand::$executionOrder)->toBe([]);
});

it('only runs installer demos for the selected theme while keeping non-theme demos', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new SharedTrackingCommand('capell:foundation-theme-demo'));
    Artisan::registerCommand(new SharedTrackingCommand('capell:theme-agency-demo'));
    Artisan::registerCommand(new SharedTrackingCommand('capell:blog-demo'));
    SharedTrackingCommand::reset();

    CapellCore::registerPackage(name: 'capell-app/foundation-theme', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/foundation-theme')->themeKey = 'foundation';
    CapellCore::getPackage('capell-app/foundation-theme')->demoCommand = 'capell:foundation-theme-demo';

    CapellCore::registerPackage(name: 'capell-app/theme-agency', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-agency')->themeKey = 'agency';
    CapellCore::getPackage('capell-app/theme-agency')->demoCommand = 'capell:theme-agency-demo';

    CapellCore::registerPackage(name: 'capell-app/blog');
    CapellCore::getPackage('capell-app/blog')->demoCommand = 'capell:blog-demo';

    $inputData = new InstallInputData(
        siteUrl: 'https://demo.example.com',
        packages: ['capell-app/foundation-theme', 'capell-app/theme-agency', 'capell-app/blog'],
        languages: ['en'],
        demoContent: true,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
        selectedThemeKey: 'foundation',
    );

    InstallPackagesAction::run($inputData, null, new NullProgressReporter);

    expect(SharedTrackingCommand::$executionOrder)->toContain(
        'capell:foundation-theme-demo',
        'capell:blog-demo',
    )->not->toContain('capell:theme-agency-demo');

    Artisan::call('capell:theme-agency-demo');

    expect(SharedTrackingCommand::$executionOrder)->toContain('capell:theme-agency-demo');
});

it('calls setup command registered via registerPackage setupCommand param', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    Artisan::registerCommand(new TrackingSetupCommand('test:setup'));
    TrackingSetupCommand::reset();

    CapellCore::registerPackage(name: 'test', setupCommand: 'test:setup');

    $user = User::factory()->createOne();
    $inputData = makeInstallInputData(packages: ['test'], seedDefaultData: true);

    InstallPackagesAction::run($inputData, $user, new NullProgressReporter);

    expect(TrackingSetupCommand::$executionOrder)->toBe(['test:setup']);
});

function makeInstallComposerPackageFixture(string $composerName): string
{
    $packagePath = sys_get_temp_dir() . '/capell-install-composer-package-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);

    file_put_contents(
        $packagePath . '/composer.json',
        json_encode(['name' => $composerName], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}
