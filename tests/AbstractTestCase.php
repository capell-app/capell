<?php

declare(strict_types=1);

namespace Capell\Tests;

use Aimeos\Nestedset\NestedSetServiceProvider;
use BezhanSalleh\FilamentShield\Support\Utils;
use Bkwld\Cloner\ServiceProvider as ClonerServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Tests\Fixtures\Components\Headers\CustomHeader as FakeCustomHeader;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Fixtures\Policies\RolePolicy;
use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Capell\Tests\Support\PackageTestDatabaseGuard;
use Filament\SpatieLaravelSettingsPluginServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\Concerns\InteractsWithSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\ActionServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Orchestra\Workbench\WorkbenchServiceProvider;
use Override;
use RuntimeException;
use Sinnbeck\DomAssertions\DomAssertionsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\EventSourcing\EventSourcingServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelRay\RayServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;
use Spatie\LaravelSettings\Migrations\SettingsMigration;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Throwable;

abstract class AbstractTestCase extends TestCase
{
    use InteractsWithSession;
    use LazilyRefreshDatabase;
    use WithFaker;
    use WithWorkbench;

    /** @var array<string, string>|null */
    private static ?array $testbenchManifestCacheFileContents = null;

    #[Override]
    protected function setUp(): void
    {
        PackageTestDatabaseGuard::assertEnvironmentIsSafe();

        // PHP 8.5 deprecates PDO::MYSQL_ATTR_* in favour of Pdo\Mysql::ATTR_*. Laravel's and
        // Testbench's database config files still reference the old constants, which emit
        // E_DEPRECATED on every config load. PHPUnit captures each notice with a stack
        // trace via debug_backtrace(), ballooning the first test's runtime to several
        // minutes. Filter only that specific deprecation at the error-handler level —
        // every other error type falls through to PHPUnit. Laravel's flushHandlersState
        // (invoked from parent::tearDown) wipes the handler stack and re-enables only
        // PHPUnit's handler, so we don't restore here; reinstalling on each setUp is
        // both correct and what keeps the handler-stack count balanced for risky checks.
        $this->ignoreDeprecatedPdoMysqlConstants();

        if (getenv('TEST_TOKEN')) {
            putenv('VIEW_COMPILED_PATH=storage/framework/views/phpunit-' . $this->getPackageServiceName() . '-parallel-' . getenv('TEST_TOKEN') . '-' . getmypid());
        }

        $this->clearTestbenchConfigCacheFile();
        $this->setUpTestbenchApplication();

        if (getenv('TEST_TOKEN')) {
            Config::set(
                'capell-frontend.static_artifacts_path',
                storage_path('framework/capell-static-artifacts/phpunit-' . $this->getPackageServiceName() . '-parallel-' . getenv('TEST_TOKEN') . '-' . getmypid()),
            );
        }

        $application = $this->app;

        if ($application !== null) {
            PackageTestDatabaseGuard::assertConfigurationIsSafe($application);
        }

        if ($application !== null && $application->bound('view')) {
            $application->make(Factory::class)->flushState();
        }

        Blade::component('headers.custom-header', FakeCustomHeader::class);

        Http::preventStrayRequests();

        Relation::morphMap([
            'user' => User::class,
        ]);

        Model::shouldBeStrict();

        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->app?->bound('view')) {
            $this->app->make(Factory::class)->flushState();
        }

        parent::tearDown();
    }

    abstract protected function getPackageServiceName(): string;

    /**
     * Boot the application from a per-process copy of the Testbench skeleton.
     *
     * Overridden here (rather than by setting $_ENV['TESTBENCH_APP_BASE_PATH']) because Testbench
     * re-seeds that env var from testbench.yaml's `laravel: '@testbench'` before every test class.
     * See IsolatedTestbenchSkeleton for why the isolation is needed.
     */
    public static function applicationBasePath(): string
    {
        return IsolatedTestbenchSkeleton::basePath();
    }

    protected function resolveApplicationConfiguration(mixed $app): void
    {
        $this->ignoreDeprecatedPdoMysqlConstants();

        parent::resolveApplicationConfiguration($app);
    }

    /**
     * Register all migration paths Capell needs for tests.
     *
     * Testbench calls this inside setUp BEFORE LazilyRefreshDatabase arms its beforeExecuting
     * listener, so loadMigrationsFrom takes the early-return branch and registers paths with
     * the migrator via after_resolving. Doing this later (e.g. in setUp) would either trigger
     * lazy refresh mid-setup (via getInstalledPackages → Schema::hasTable) or route paths
     * through MigrateProcessor, which mis-parses list-arrays as positional artisan arguments.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (RefreshDatabaseState::$migrated) {
            return;
        }

        foreach ($this->resolveMigrationPaths() as $migrationPath) {
            $this->loadMigrationsFrom($migrationPath);
        }
    }

    /**
     * Override migrate:fresh to use explicit paths so the testbench skeleton's
     * database/migrations directory (auto-populated by package:discover) is excluded.
     * Without this, published migration stubs conflict with the paths in tests/database/migrations.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        $seeder = $this->seeder();

        return array_merge(
            [
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
            ],
            $seeder !== null ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()],
            [
                '--path' => $this->resolveMigrationPaths(),
                '--realpath' => true,
            ],
        );
    }

    /**
     * Compute all migration paths for this test run.
     *
     * Includes the testbench-core laravel/migrations directory (users, cache, jobs tables)
     * but excludes the testbench-core laravel/database/migrations directory, which is
     * auto-populated by package:discover with published migration stubs that conflict
     * with the migrations in tests/database/migrations.
     *
     * @return array<int, string>
     */
    protected function resolveMigrationPaths(): array
    {
        // testbench-core ships default Laravel migrations (users, cache, jobs) in
        // laravel/migrations/, separate from laravel/database/migrations/ which gets
        // populated by package:discover with published stubs.
        $testbenchMigrations = realpath(dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/migrations');
        $paths = $testbenchMigrations !== false ? [$testbenchMigrations] : [];
        $paths[] = __DIR__ . '/database/migrations';

        $coreMigrations = CapellCore::getMigrations();
        $corePath = realpath(dirname(__DIR__) . '/packages/core/database/migrations');

        throw_unless($corePath, RuntimeException::class, 'Could not find core migrations path.');

        array_walk($coreMigrations, fn (string &$migration): string => $migration = sprintf('%s/%s.php', $corePath, $migration));
        $paths = array_merge($paths, $coreMigrations);

        CapellCore::getInstalledPackages()->each(function (PackageData $package) use (&$paths): void {
            $paths = array_merge($paths, $this->discoverPackageMigrations($package->path));
        });

        return array_values(array_filter($paths, is_string(...)));
    }

    protected function getEnvironmentSetUp(mixed $app): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('database.connections.sqlite.url');

        $this->registerBladeIconConfigs();
        $this->registerPackageConfigs($app);

        Gate::policy(Utils::getRoleModel(), RolePolicy::class);
    }

    /**
     * Set up the database.
     */
    protected function setUpDatabase(): void
    {
        Role::findOrCreate('super_admin', 'web');
    }

    protected function getDefaultPackageProviders(): array
    {
        return [
            WorkbenchServiceProvider::class,
            ActionServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            ClonerServiceProvider::class,
            LaravelDataServiceProvider::class,
            NestedSetServiceProvider::class,
            PermissionServiceProvider::class,
            RayServiceProvider::class,
            DomAssertionsServiceProvider::class,
            SpatieLaravelSettingsPluginServiceProvider::class,
            EventSourcingServiceProvider::class,
            CapellServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelSettingsServiceProvider::class,
        ];
    }

    protected function registerPackageConfigs(Application $app, ?array $packages = null): void
    {
        if ($packages === null || $packages === []) {
            $packages = $this->getDefaultPackages();
        }

        $this->registerPublishConfig('core');

        foreach ($packages as $package_key => $package) {
            $config = require dirname(__DIR__) . $this->getPackageFile($package);

            $this->registerPackageConfig($package_key, $config);
        }

        Config::set('filament-shield.authenticable-resources', [User::class]);
        Config::set('filament-shield.auth_provider_model', User::class);

        // Prevent role being assigned to created user
        Config::set('filament-shield.panel_user.enabled', false);

        Config::set('auth.providers.users.model', User::class);

        Config::set('filesystems.disks.page_cache', [
            'driver' => 'local',
            'root' => public_path('page-cache'),
            'throw' => false,
        ]);

        if (getenv('TEST_TOKEN')) {
            Config::set('settings.cache.prefix', 'settings-cache-' . getenv('TEST_TOKEN'));
        }
    }

    protected function getDefaultPackages(): array
    {
        return [
            'filament-shield' => [
                'user' => 'bezhansalleh',
                'name' => 'filament-shield',
                'file' => 'filament-shield',
            ],
            'permission' => [
                'user' => 'spatie',
                'name' => 'laravel-permission',
                'file' => 'permission',
            ],
            'settings' => [
                'user' => 'spatie',
                'name' => 'laravel-settings',
                'file' => 'settings',
            ],
        ];
    }

    protected function registerPublishConfig(string $package): void
    {
        $configs = $this->getPublishConfigs($package);

        foreach ($configs as $configFile) {
            $config = require $configFile;
            $configName = basename((string) $configFile, '.php');

            $this->registerPackageConfig($configName, $config);
        }
    }

    protected function getPublishConfigs(string $package): array
    {
        $path = realpath(dirname(__DIR__) . '/packages/' . $package . '/publishes/config');

        if (in_array($path, ['', '0', false], true)) {
            return [];
        }

        $configs = glob($path . '/*.php');

        return $configs === false ? [] : $configs;
    }

    protected function registerAndMigrateSettings(array $migrations, string $basePath): void
    {
        foreach ($migrations as $migrationFile) {
            $path = sprintf('%s/%s.php', $basePath, $migrationFile);
            /** @var SettingsMigration $migration */
            $migration = require $path;

            $migration->up();
        }
    }

    private function restoreTestbenchManifestCacheFiles(): void
    {
        $cacheDirectory = static::applicationBasePath() . '/bootstrap/cache';
        $cacheFiles = [
            'packages.php',
            'services.php',
        ];

        if (! is_dir($cacheDirectory)) {
            return;
        }

        if (self::$testbenchManifestCacheFileContents === null) {
            self::$testbenchManifestCacheFileContents = [];

            foreach ($cacheFiles as $cacheFile) {
                $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheFile;

                if (is_file($cachePath)) {
                    self::$testbenchManifestCacheFileContents[$cacheFile] = (string) file_get_contents($cachePath);
                }
            }
        }

        foreach (self::$testbenchManifestCacheFileContents as $cacheFile => $contents) {
            $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheFile;

            if (! is_file($cachePath)) {
                file_put_contents($cachePath, $contents, LOCK_EX);
            }
        }
    }

    private function clearTestbenchConfigCacheFile(): void
    {
        $configCachePath = static::applicationBasePath() . '/bootstrap/cache/config.php';

        if (is_file($configCachePath)) {
            unlink($configCachePath);
        }
    }

    private function setUpTestbenchApplication(): void
    {
        $attempts = 0;

        while (true) {
            $this->restoreTestbenchManifestCacheFiles();

            try {
                parent::setUp();

                return;
            } catch (Throwable $throwable) {
                $attempts++;

                throw_if($attempts >= 3 || ! $this->isMissingTestbenchManifestCacheFile($throwable), $throwable);

                Sleep::usleep(100_000);
            }
        }
    }

    private function isMissingTestbenchManifestCacheFile(Throwable $throwable): bool
    {
        return str_contains($throwable->getMessage(), '/bootstrap/cache/')
            && (
                str_contains($throwable->getMessage(), 'packages.php')
                || str_contains($throwable->getMessage(), 'services.php')
            );
    }

    private function registerBladeIconConfigs(): void
    {
        Config::set(
            'blade-heroicons',
            require dirname(__DIR__) . '/vendor/blade-ui-kit/blade-heroicons/config/blade-heroicons.php',
        );

        Config::set(
            'blade-icons',
            require dirname(__DIR__) . '/vendor/blade-ui-kit/blade-icons/config/blade-icons.php',
        );
    }

    private function ignoreDeprecatedPdoMysqlConstants(): void
    {
        set_error_handler(
            static fn (int $errno, string $errstr): bool => $errno === E_DEPRECATED
                && str_contains($errstr, 'PDO::MYSQL_ATTR'),
            E_DEPRECATED,
        );
    }

    private function getPackageFile(array $package): string
    {
        $path = '/vendor/' . basename((string) $package['user']) . '/' . basename((string) $package['name']) . '/config';
        $file = basename((string) $package['file']) . '.php';

        return sprintf('%s/%s', $path, $file);
    }

    private function registerPackageConfig(string $package, array $config): void
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->registerPackageConfig(sprintf('%s.%s', $package, $key), $value);

                continue;
            }

            config()->set(sprintf('%s.%s', $package, $key), $value);
        }
    }

    private function discoverPackageMigrations(string $path): array
    {
        $path = realpath($path . '/database/migrations');

        if (! $path) {
            return [];
        }

        $files = glob($path . '/*.php');

        return $files === false ? [] : $files;
    }
}
