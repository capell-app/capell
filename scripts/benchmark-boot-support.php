<?php

declare(strict_types=1);

namespace Capell\Benchmark;

use Aimeos\Nestedset\NestedSetServiceProvider;
use AmidEsfahani\FilamentTinyEditor\TinyeditorServiceProvider;
use Awcodes\BadgeableColumn\BadgeableColumnServiceProvider;
use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use CmsMulti\FilamentClearCache\FilamentClearCacheServiceProvider;
use CodeWithDennis\FilamentSelectTree\FilamentSelectTreeServiceProvider;
use Composer\InstalledVersions;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\SpatieLaravelSettingsPluginServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Guava\IconPicker\IconPickerServiceProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LaraZeus\SpatieTranslatable\SpatieTranslatableServiceProvider;
use Livewire\LivewireServiceProvider;
use Lorisleiva\Actions\ActionServiceProvider;
use Pboivin\FilamentPeek\FilamentPeekServiceProvider;
use RuntimeException;
use Saade\FilamentAdjacencyList\FilamentAdjacencyListServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\EventSourcing\EventSourcingServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use STS\FilamentImpersonate\FilamentImpersonateServiceProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tanmuhittin\LaravelGoogleTranslate\LaravelGoogleTranslateServiceProvider;
use Throwable;
use Workbench\App\Providers\ScreenshotWorkbenchServiceProvider;

final readonly class BootBenchmarkOptions
{
    public function __construct(
        public string $profile,
        public string $cache,
        public int $iterations,
        public int $warmups,
        public string $format,
        public bool $profiling,
    ) {}

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        $values = [
            'profile' => 'production',
            'cache' => 'optimized',
            'iterations' => 25,
            'warmups' => 5,
            'format' => 'text',
            'profiling' => false,
        ];
        $positionals = [];
        $iterationsProvided = false;

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                $positionals[] = $argument;

                continue;
            }

            if ($argument === '--profiling') {
                $values['profiling'] = true;

                continue;
            }

            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

            if (! in_array($name, ['profile', 'cache', 'iterations', 'warmups', 'format'], true) || $value === null || $value === '') {
                throw new InvalidArgumentException(sprintf('Unknown or incomplete option [%s].', $argument));
            }

            $values[$name] = $value;

            if ($name === 'iterations') {
                $iterationsProvided = true;
            }
        }

        if (count($positionals) > 1 || ($positionals !== [] && $iterationsProvided)) {
            throw new InvalidArgumentException('Provide iterations either positionally or with --iterations, not both.');
        }

        if ($positionals !== []) {
            $values['iterations'] = $positionals[0];
        }

        $iterations = self::integer($values['iterations'], 'iterations', 3, 100);
        $warmups = self::integer($values['warmups'], 'warmups', 0, 25);
        $profile = self::choice($values['profile'], 'profile', ['full', 'production', 'public', 'admin']);
        $cache = self::choice($values['cache'], 'cache', ['manifest', 'optimized', 'uncached']);
        $format = self::choice($values['format'], 'format', ['text', 'json']);

        return new self($profile, $cache, $iterations, $warmups, $format, $values['profiling'] === true);
    }

    public static function usage(): string
    {
        return 'Usage: composer benchmark:boot -- [iterations: 3-100] [--profile=full|production|public|admin] [--cache=manifest|optimized|uncached] [--iterations=3-100] [--warmups=0-25] [--format=text|json] [--profiling]';
    }

    private static function integer(mixed $value, string $name, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        if (! is_int($validated)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer between %d and %d.', ucfirst($name), $minimum, $maximum));
        }

        return $validated;
    }

    /** @param list<string> $choices */
    private static function choice(mixed $value, string $name, array $choices): string
    {
        if (! is_string($value) || ! in_array($value, $choices, true)) {
            throw new InvalidArgumentException(sprintf('%s must be one of: %s.', ucfirst($name), implode(', ', $choices)));
        }

        return $value;
    }
}

final class BootStatistics
{
    /**
     * @param  list<float>  $samples
     * @return array{p50: float, p75: float, p95: float, iqr: float, trimmed_mean: float, outliers: list<float>, samples: list<float>}
     */
    public static function summarize(array $samples): array
    {
        if (count($samples) < 3) {
            throw new InvalidArgumentException('At least three samples are required.');
        }

        $sorted = $samples;
        sort($sorted, SORT_NUMERIC);
        $q1 = self::percentile($sorted, 25);
        $q3 = self::percentile($sorted, 75);
        $iqr = $q3 - $q1;
        $lowerFence = $q1 - (1.5 * $iqr);
        $upperFence = $q3 + (1.5 * $iqr);
        $trim = (int) floor(count($sorted) * 0.1);
        $trimmed = $trim > 0 ? array_slice($sorted, $trim, -$trim) : $sorted;

        return [
            'p50' => self::round(self::percentile($sorted, 50)),
            'p75' => self::round($q3),
            'p95' => self::round(self::percentile($sorted, 95)),
            'iqr' => self::round($iqr),
            'trimmed_mean' => self::round(array_sum($trimmed) / count($trimmed)),
            'outliers' => array_values(array_map(
                self::round(...),
                array_filter($sorted, static fn (float $sample): bool => $sample < $lowerFence || $sample > $upperFence),
            )),
            'samples' => array_map(self::round(...), $samples),
        ];
    }

    /** @param list<float> $sorted */
    public static function percentile(array $sorted, int $percentile): float
    {
        if ($sorted === [] || $percentile < 0 || $percentile > 100) {
            throw new InvalidArgumentException('Percentiles require samples and a value from 0 to 100.');
        }

        $position = (count($sorted) - 1) * ($percentile / 100);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + (($sorted[$upper] - $sorted[$lower]) * ($position - $lower));
    }

    private static function round(float $value): float
    {
        return round($value, 3);
    }
}

final class BootProfiles
{
    /** @return list<class-string> */
    public static function providers(string $profile): array
    {
        $runtime = [
            ActionServiceProvider::class,
            NestedSetServiceProvider::class,
            \Bkwld\Cloner\ServiceProvider::class,
            LaravelDataServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelSettingsServiceProvider::class,
            EventSourcingServiceProvider::class,
        ];
        $filament = [
            SpatieLaravelSettingsPluginServiceProvider::class,
            LivewireServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            PermissionServiceProvider::class,
            BadgeableColumnServiceProvider::class,
            SpatieTranslatableServiceProvider::class,
            TinyeditorServiceProvider::class,
            FilamentAdjacencyListServiceProvider::class,
            FilamentShieldServiceProvider::class,
            FilamentSelectTreeServiceProvider::class,
            FilamentClearCacheServiceProvider::class,
            FilamentPeekServiceProvider::class,
            FilamentImpersonateServiceProvider::class,
            IconPickerServiceProvider::class,
            LaravelGoogleTranslateServiceProvider::class,
        ];

        return match ($profile) {
            'public' => [...$runtime, CapellServiceProvider::class, FrontendServiceProvider::class],
            'admin' => [...$runtime, ...$filament, CapellServiceProvider::class, AdminServiceProvider::class, FrontendServiceProvider::class, MarketplaceServiceProvider::class, AdminPanelProvider::class],
            'production' => [...$runtime, ...$filament, CapellServiceProvider::class, AdminServiceProvider::class, FrontendServiceProvider::class, InstallerServiceProvider::class, MarketplaceServiceProvider::class, AdminPanelProvider::class],
            'full' => [...self::providers('production'), ScreenshotWorkbenchServiceProvider::class],
            default => throw new InvalidArgumentException(sprintf('Unknown benchmark profile [%s].', $profile)),
        };
    }
}

final readonly class BootBenchmarkWorkspace
{
    private Filesystem $files;

    public function __construct(
        private string $root,
        public string $path,
        private string $profile,
    ) {
        $this->files = new Filesystem;
    }

    public static function create(string $root, string $profile): self
    {
        $path = sys_get_temp_dir() . '/capell-boot-benchmark-' . getmypid() . '-' . bin2hex(random_bytes(5));
        $workspace = new self($root, $path, $profile);
        $workspace->prepare();

        return $workspace;
    }

    public function prepareCache(string $mode): void
    {
        $cachePath = $this->path . '/laravel/bootstrap/cache';
        $this->files->mkdir($cachePath);

        foreach (glob($cachePath . '/*.php') ?: [] as $file) {
            $this->files->remove($file);
        }

        if ($mode === 'optimized') {
            $this->command(['optimize', '--except=routes,views', '--no-ansi'], runningInConsole: true)->mustRun();
            $this->normalizeOptimizedProviderCache();
        }

        if ($mode === 'manifest') {
            $this->command(['capell:package-cache', '--no-ansi'], runningInConsole: true)->mustRun();
        }

        if ($mode !== 'uncached') {
            $this->validateManifest();
        }
    }

    public function process(bool $runningInConsole): Process
    {
        return new Process(
            [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=1',
                '-d',
                'opcache.file_cache=' . $this->path . '/opcache',
                '-d',
                'opcache.file_cache_only=1',
                $this->root . '/scripts/benchmark-boot-child.php',
                $this->root,
                $this->path . '/laravel',
            ],
            $this->root,
            [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_RUNNING_IN_CONSOLE' => $runningInConsole ? 'true' : 'false',
                'CACHE_STORE' => 'array',
            ],
        );
    }

    public function remove(): void
    {
        $this->files->remove($this->path);
    }

    private function prepare(): void
    {
        $this->files->mkdir($this->path);
        $this->files->mkdir($this->path . '/opcache');
        $this->files->mirror(
            $this->root . '/vendor/orchestra/testbench-core/laravel',
            $this->path . '/laravel',
            null,
            ['override' => true, 'delete' => true],
        );

        foreach (['vendor', 'packages'] as $directory) {
            $this->files->symlink($this->root . '/' . $directory, $this->path . '/' . $directory);
        }

        if ($this->profile === 'full') {
            $this->files->symlink($this->root . '/workbench', $this->path . '/workbench');
        }

        $composer = json_decode(
            (string) file_get_contents($this->root . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $composer['extra']['laravel']['dont-discover'] = ['*'];
        $this->files->dumpFile(
            $this->path . '/composer.json',
            json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );
        $this->files->symlink($this->root . '/composer.lock', $this->path . '/composer.lock');

        $yaml = [
            "laravel: './laravel'",
            'env:',
            "  AUTH_MODEL: 'Capell\\\\Tests\\\\Fixtures\\\\Models\\\\User'",
            "  APP_KEY: 'base64:/MjiNkPfjAngJBfuMDsnFBxDynZGOKk3O6P0u0MhvJE='",
            "  CACHE_STORE: 'array'",
            'providers:',
            ...array_map(static fn (string $provider): string => '  - ' . $provider, BootProfiles::providers($this->profile)),
            'dont-discover:',
            "  - '*'",
            'workbench:',
            '  discovers:',
            '    config: true',
        ];
        $this->files->dumpFile($this->path . '/testbench.yaml', implode(PHP_EOL, $yaml) . PHP_EOL);
        $this->files->dumpFile(
            $this->path . '/laravel/bootstrap/providers.php',
            '<?php return ' . var_export(BootProfiles::providers($this->profile), return: true) . ';' . PHP_EOL,
        );
    }

    /** @param list<string> $arguments */
    private function command(array $arguments, bool $runningInConsole = false): Process
    {
        return new Process(
            [PHP_BINARY, $this->root . '/vendor/bin/testbench', ...$arguments],
            $this->root,
            [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_RUNNING_IN_CONSOLE' => $runningInConsole ? 'true' : 'false',
                'CACHE_STORE' => 'array',
                'TESTBENCH_WORKING_PATH' => $this->path,
            ],
        );
    }

    private function validateManifest(): void
    {
        $path = $this->path . '/laravel/bootstrap/cache/capell-package-manifests.php';

        try {
            $contents = is_file($path) ? require $path : null;
        } catch (Throwable) {
            $contents = null;
        }

        $sourcePaths = [
            $this->root . '/composer.lock',
            ...glob($this->root . '/packages/*/capell.json') ?: [],
        ];
        $newestSourceTime = max(array_map(
            static fn (string $source): int => filemtime($source) ?: 0,
            $sourcePaths,
        ));
        $stale = is_file($path) && (filemtime($path) ?: 0) < $newestSourceTime;

        if ($stale || ! is_array($contents) || array_filter($contents, static fn (mixed $manifest): bool => ! is_array($manifest)) !== []) {
            $this->files->remove($path);
            $this->command(['capell:package-cache', '--no-ansi'], runningInConsole: true)->mustRun();
            $contents = require $path;

            if (! is_array($contents)) {
                throw new RuntimeException('The regenerated Capell package manifest cache is invalid.');
            }
        }
    }

    private function normalizeOptimizedProviderCache(): void
    {
        $cachePath = $this->path . '/laravel/bootstrap/cache';
        /** @var array<string, mixed> $config */
        $config = require $cachePath . '/config.php';
        $config['app']['providers'] = [
            ...ServiceProvider::defaultProviders()->toArray(),
            ...BootProfiles::providers($this->profile),
        ];

        $this->files->dumpFile(
            $cachePath . '/config.php',
            '<?php return ' . var_export($config, return: true) . ';' . PHP_EOL,
        );
        $this->files->dumpFile($cachePath . '/packages.php', '<?php return [];' . PHP_EOL);
        $this->files->remove($cachePath . '/services.php');
    }
}

final class BootBenchmark
{
    public function __construct(private readonly string $root) {}

    /** @param array<string, mixed> $result */
    public static function formatText(array $result): string
    {
        /** @var array<string, mixed> $benchmark */
        $benchmark = $result['benchmark'];
        /** @var array<string, mixed> $statistics */
        $statistics = $result['statistics_ms'];

        return sprintf(
            "Capell %s boot (%s cache): %.2f ms p50, %.2f ms p75, %.2f ms p95\nIQR: %.2f ms; trimmed mean: %.2f ms; outliers: %s\nSamples (%d after %d warmups): %s\nFingerprint: %s\n",
            $benchmark['profile'],
            $benchmark['cache'],
            $statistics['p50'],
            $statistics['p75'],
            $statistics['p95'],
            $statistics['iqr'],
            $statistics['trimmed_mean'],
            $statistics['outliers'] === [] ? 'none' : implode(', ', $statistics['outliers']),
            $benchmark['iterations'],
            $benchmark['warmups'],
            implode(', ', $statistics['samples']),
            hash('sha256', json_encode($result['fingerprint'], JSON_THROW_ON_ERROR)),
        );
    }

    /** @return array<string, mixed> */
    public function run(BootBenchmarkOptions $options): array
    {
        $workspace = BootBenchmarkWorkspace::create($this->root, $options->profile);

        try {
            $workspace->prepareCache($options->cache);
            $samples = [];
            $frameworkSamples = [];
            $providerSamples = [];

            for ($iteration = 0; $iteration < $options->warmups + $options->iterations; $iteration++) {
                $process = $workspace->process($options->cache === 'uncached');
                $startedAt = hrtime(true);
                $process->mustRun();
                $elapsed = (hrtime(true) - $startedAt) / 1_000_000;
                /** @var array{framework_ms: float, providers_ms: array<string, array{register: float, boot: float}>} $profile */
                $profile = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

                if ($iteration >= $options->warmups) {
                    $samples[] = $elapsed;
                    $frameworkSamples[] = $profile['framework_ms'];

                    foreach ($profile['providers_ms'] as $provider => $timings) {
                        $providerSamples[$provider]['register'][] = $timings['register'];
                        $providerSamples[$provider]['boot'][] = $timings['boot'];
                    }
                }
            }

            $statistics = BootStatistics::summarize($samples);
            $providers = BootProfiles::providers($options->profile);
            $fingerprint = $this->fingerprint($workspace, $options, $providers);

            return [
                'schema_version' => 1,
                'benchmark' => [
                    'profile' => $options->profile,
                    'cache' => $options->cache,
                    'iterations' => $options->iterations,
                    'warmups' => $options->warmups,
                ],
                'statistics_ms' => $statistics,
                'profiling_ms' => $options->profiling
                    ? $this->profiling($samples, $frameworkSamples, $providerSamples)
                    : null,
                'fingerprint' => $fingerprint,
            ];
        } finally {
            $workspace->remove();
        }
    }

    /**
     * @param  list<string>  $providers
     * @return array<string, mixed>
     */
    private function fingerprint(BootBenchmarkWorkspace $workspace, BootBenchmarkOptions $options, array $providers): array
    {
        $configPath = $workspace->path . '/laravel/bootstrap/cache/config.php';
        $manifestPath = $workspace->path . '/laravel/bootstrap/cache/capell-package-manifests.php';

        return [
            'git_sha' => trim($this->process(['git', 'rev-parse', 'HEAD'])),
            'composer_lock_sha256' => hash_file('sha256', $this->root . '/composer.lock'),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'opcache' => [
                'enabled' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL),
                'cli_enabled' => true,
                'file_cache' => true,
            ],
            'laravel_version' => InstalledVersions::getPrettyVersion('laravel/framework'),
            'testbench_version' => InstalledVersions::getPrettyVersion('orchestra/testbench'),
            'filament_version' => InstalledVersions::getPrettyVersion('filament/filament'),
            'providers' => $providers,
            'providers_sha256' => hash('sha256', implode("\n", $providers)),
            'package_manifest_sha256' => is_file($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'config_cached' => is_file($configPath),
            'profile' => $options->profile,
            'cache' => $options->cache,
        ];
    }

    /**
     * @param  list<float>  $processSamples
     * @param  list<float>  $frameworkSamples
     * @param  array<string, array{register: list<float>, boot: list<float>}>  $providerSamples
     * @return array<string, mixed>
     */
    private function profiling(array $processSamples, array $frameworkSamples, array $providerSamples): array
    {
        $providerProfiles = [];

        foreach ($providerSamples as $provider => $timings) {
            $providerProfiles[$provider] = [
                'register_p50' => BootStatistics::summarize($timings['register'])['p50'],
                'boot_p50' => BootStatistics::summarize($timings['boot'])['p50'],
            ];
        }

        $framework = BootStatistics::summarize($frameworkSamples);
        $overhead = array_map(
            static fn (float $process, float $inside): float => max(0.0, $process - $inside),
            $processSamples,
            $frameworkSamples,
        );

        return [
            'framework_p50' => $framework['p50'],
            'process_overhead_p50' => BootStatistics::summarize($overhead)['p50'],
            'providers' => $providerProfiles,
            'capell' => [
                'core' => $providerProfiles[CapellServiceProvider::class] ?? null,
                'admin' => $providerProfiles[AdminServiceProvider::class] ?? null,
                'frontend' => $providerProfiles[FrontendServiceProvider::class] ?? null,
                'marketplace' => $providerProfiles[MarketplaceServiceProvider::class] ?? null,
            ],
        ];
    }

    /** @param list<string> $command */
    private function process(array $command): string
    {
        $process = new Process($command, $this->root);
        $process->mustRun();

        return $process->getOutput();
    }
}
