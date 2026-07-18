<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Composer\InstalledVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->previousInstallerMemoryLimit = ini_get('memory_limit');
    ini_set('memory_limit', '512M');
});

afterEach(function (): void {
    if (is_string($this->previousInstallerMemoryLimit)) {
        ini_set('memory_limit', $this->previousInstallerMemoryLimit);
    }
});

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function installerPreflightCheck(array $report, string $key): array
{
    $checks = $report['checks'] ?? [];

    assert(is_array($checks));

    $check = collect($checks)->firstWhere('key', $key);

    assert(is_array($check));

    return $check;
}

it('summarizes blocking and warning checks', function (): void {
    $checks = [
        ['status' => 'pass'],
        ['status' => 'warning'],
        ['status' => 'fail'],
    ];

    expect(InstallerPreflight::hasBlockingFailures($checks))->toBeTrue()
        ->and(InstallerPreflight::statusFor($checks))->toBe('fail');
});

it('does not treat advisory failures as install blockers', function (): void {
    $checks = [
        ['status' => 'fail', 'severity' => 'advisory'],
    ];

    expect(InstallerPreflight::hasBlockingFailures($checks))->toBeFalse();
});

it('reports the current environment with remediation fields', function (): void {
    $report = resolve(InstallerPreflight::class)->run();

    expect($report)
        ->toHaveKeys(['status', 'checks', 'groups', 'environment', 'generatedAt'])
        ->and($report['checks'])->toBeArray()->not->toBeEmpty()
        ->and($report['groups'])->toHaveKeys(['blocking', 'advisory'])
        ->and($report['environment'])->toHaveKeys(['php', 'memoryLimit', 'laravel', 'os', 'sapi', 'paths'])
        ->and($report['checks'][0])->toHaveKeys(['key', 'label', 'status', 'severity', 'message', 'remediation']);

    if (InstalledVersions::isInstalled('filament/filament')) {
        expect($report['environment'])->toHaveKey('filament');
    }

    if (InstalledVersions::isInstalled('livewire/livewire')) {
        expect($report['environment'])->toHaveKey('livewire');
    }
});

it('blocks browser installation when the web php memory limit is below the floor', function (): void {
    ini_set('memory_limit', '128M');

    $report = resolve(InstallerPreflight::class)->run();
    $memoryCheck = installerPreflightCheck($report, 'php-memory-limit');

    expect($memoryCheck)
        ->toMatchArray([
            'label' => 'PHP memory limit',
            'status' => 'fail',
            'message' => 'Capell installation requires PHP memory_limit of at least 512M; the current limit is 128M.',
        ])
        ->and($memoryCheck['remediation'])->toContain('php -d memory_limit=512M artisan capell:install')
        ->and($report['environment']['memoryLimit'])->toBe('128M')
        ->and(InstallerPreflight::hasBlockingFailures($report['checks']))->toBeTrue();
});

it('accepts unlimited web php memory', function (): void {
    ini_set('memory_limit', '-1');

    $report = resolve(InstallerPreflight::class)->run();

    expect(installerPreflightCheck($report, 'php-memory-limit'))
        ->toMatchArray([
            'status' => 'pass',
            'message' => 'PHP memory_limit=-1 is available for Capell installation.',
        ]);
});

it('accepts the documented minimum PHP version', function (): void {
    $report = resolve(InstallerPreflight::class)->run();
    $phpVersionCheck = installerPreflightCheck($report, 'php-version');

    expect($phpVersionCheck)
        ->toMatchArray([
            'label' => 'PHP version',
            'status' => 'pass',
            'message' => 'PHP ' . PHP_VERSION . ' is compatible with Capell.',
        ]);
});

it('checks the configured cli php binary through path resolution', function (): void {
    config(['capell-installer.php_binary' => 'php']);

    $report = resolve(InstallerPreflight::class)->run();
    $phpBinaryCheck = installerPreflightCheck($report, 'php-binary');

    expect($phpBinaryCheck['status'])->toBe('pass');
});

it('reports missing configured PHP and Git binaries without blocking package-free installs', function (): void {
    $originalPath = getenv('PATH');

    putenv('PATH=');
    config([
        'capell-installer.php_binary' => '/missing/php',
        'capell-installer.composer_binary' => '/missing/composer',
    ]);

    try {
        $report = resolve(InstallerPreflight::class)->run();

        expect(installerPreflightCheck($report, 'php-binary'))
            ->toMatchArray([
                'status' => 'warning',
                'message' => 'The configured PHP binary could not be resolved.',
            ])
            ->and(installerPreflightCheck($report, 'git-binary'))
            ->toMatchArray([
                'status' => 'warning',
                'message' => 'Git is not available to the web PHP process.',
            ])
            ->and(installerPreflightCheck($report, 'composer-binary'))
            ->toMatchArray([
                'status' => 'warning',
            ]);
    } finally {
        putenv('PATH=' . ($originalPath === false ? '' : $originalPath));
        config([
            'capell-installer.php_binary' => 'php',
            'capell-installer.composer_binary' => 'composer',
        ]);
    }
});

it('reports configured PHP binary command failures once per resolved command', function (): void {
    $temporaryDirectory = storage_path('framework/testing/installer-preflight-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $counterPath = $temporaryDirectory . '/php-counter.txt';
    $fakePhpPath = $temporaryDirectory . '/php';
    File::put($fakePhpPath, "#!/bin/sh\necho run >> '{$counterPath}'\nprintf '%s\\n' 'php failed from fixture' >&2\nexit 2\n");
    chmod($fakePhpPath, 0755);

    config(['capell-installer.php_binary' => $fakePhpPath]);

    try {
        $firstReport = resolve(InstallerPreflight::class)->run();
        $secondReport = resolve(InstallerPreflight::class)->run();

        expect(installerPreflightCheck($firstReport, 'php-binary'))
            ->toMatchArray([
                'status' => 'fail',
                'message' => 'php failed from fixture',
                'remediation' => 'Make sure the command is executable by the web PHP process.',
            ])
            ->and(installerPreflightCheck($secondReport, 'php-binary')['message'])->toBe('php failed from fixture')
            ->and(substr_count(File::get($counterPath), 'run'))->toBe(1);
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.php_binary' => 'php']);
    }
});

it('warns when the configured php binary points at php fpm', function (): void {
    $temporaryDirectory = storage_path('framework/testing/installer-preflight-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $fakePhpFpmPath = $temporaryDirectory . '/php-fpm';
    File::put($fakePhpFpmPath, "#!/bin/sh\necho \"PHP FPM\"\nexit 0\n");
    chmod($fakePhpFpmPath, 0755);
    config(['capell-installer.php_binary' => $fakePhpFpmPath]);

    try {
        $report = resolve(InstallerPreflight::class)->run();
        $phpBinaryCheck = installerPreflightCheck($report, 'php-binary');

        expect($phpBinaryCheck)
            ->toMatchArray([
                'status' => 'warning',
                'message' => 'The configured PHP binary points at php-fpm, not CLI PHP.',
            ]);
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.php_binary' => 'php']);
    }
});

it('warns about installer-managed paths when the application base path cannot be written', function (): void {
    $originalBasePath = $this->app->basePath();

    $this->app->setBasePath('/sys/capell-preflight-unwritable');

    try {
        $report = resolve(InstallerPreflight::class)->run();

        expect(installerPreflightCheck($report, 'bootstrap-cache-writable'))
            ->toMatchArray([
                'status' => 'fail',
                'message' => 'Directory is not writable by the web PHP process.',
            ])
            ->and(installerPreflightCheck($report, 'application-files-writable'))
            ->toMatchArray([
                'status' => 'warning',
            ])
            ->and(installerPreflightCheck($report, 'public-output-writable'))
            ->toMatchArray([
                'status' => 'warning',
            ]);
    } finally {
        $this->app->setBasePath($originalBasePath);
    }
});

it('blocks database cache stores when the cache table has not been migrated', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.table' => 'missing_installer_cache_table',
    ]);

    $report = resolve(InstallerPreflight::class)->run();
    $cacheCheck = installerPreflightCheck($report, 'cache-store');

    expect($cacheCheck)
        ->toMatchArray([
            'label' => 'Setup cache store',
            'status' => 'fail',
        ])
        ->and($cacheCheck['message'])->toContain('CACHE_STORE=database')
        ->and($cacheCheck['remediation'])->toContain('CACHE_STORE=file');
});

it('passes database cache store preflight when the configured cache table exists', function (): void {
    Schema::create('installer_preflight_cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    config([
        'cache.default' => 'database',
        'cache.stores.database.table' => 'installer_preflight_cache',
    ]);

    $report = resolve(InstallerPreflight::class)->run();
    $cacheCheck = installerPreflightCheck($report, 'cache-store');

    expect($cacheCheck)
        ->toMatchArray([
            'label' => 'Setup cache store',
            'status' => 'pass',
            'message' => 'CACHE_STORE=database is usable because the installer_preflight_cache table exists.',
        ]);
});

it('warns when the configured database connection is not reachable', function (): void {
    $originalDefaultConnection = config('database.default');
    $originalCacheStore = config('cache.default');

    config([
        'database.default' => 'installer_missing_connection',
        'database.connections.installer_missing_connection' => [
            'driver' => 'sqlite',
            'database' => '/sys/capell-missing/install.sqlite',
            'prefix' => '',
        ],
        'cache.default' => 'array',
    ]);

    DB::purge('installer_missing_connection');

    try {
        $report = resolve(InstallerPreflight::class)->run();
        $databaseCheck = installerPreflightCheck($report, 'database-connection');

        expect($databaseCheck)
            ->toMatchArray([
                'status' => 'warning',
                'label' => 'Database connection',
            ])
            ->and($databaseCheck['message'])->toContain('not currently reachable')
            ->and($databaseCheck['remediation'])->toContain('CREATE DATABASE');
    } finally {
        config([
            'database.default' => $originalDefaultConnection,
            'cache.default' => $originalCacheStore,
        ]);
        DB::purge('installer_missing_connection');
        DB::purge(is_string($originalDefaultConnection) ? $originalDefaultConnection : null);
    }
});

it('reports public output and queue worker requirements', function (): void {
    config(['queue.default' => 'database']);

    $report = resolve(InstallerPreflight::class)->run();
    $applicationFilesCheck = installerPreflightCheck($report, 'application-files-writable');
    $publicOutputCheck = installerPreflightCheck($report, 'public-output-writable');
    $queueWorkerCheck = installerPreflightCheck($report, 'queue-worker');

    expect($applicationFilesCheck)
        ->toMatchArray([
            'label' => 'Application files',
        ])
        ->and($applicationFilesCheck['message'])->toContain('app files')
        ->and($publicOutputCheck)
        ->toMatchArray([
            'label' => 'Public output',
        ])
        ->and($publicOutputCheck['message'])->toContain('page cache')
        ->and($publicOutputCheck['message'])->toContain('asset')
        ->and($queueWorkerCheck)
        ->toMatchArray([
            'label' => 'Queue worker',
            'status' => 'warning',
        ])
        ->and($queueWorkerCheck['message'])->toContain('QUEUE_CONNECTION=database')
        ->and($queueWorkerCheck['remediation'])->toContain('https://laravel.com/docs/queues#running-the-queue-worker');
});

it('reports composer file permissions when packages will be required', function (): void {
    $report = resolve(InstallerPreflight::class)->run(new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: ['capell-app/frontend'],
        integrateAdminPanel: false,
        installDeveloperTooling: false,
        configureBoostDeveloperTooling: false,
    ));

    $composerFilesCheck = installerPreflightCheck($report, 'composer-files-writable');

    expect($composerFilesCheck)
        ->toMatchArray([
            'label' => 'Composer files',
        ])
        ->and($composerFilesCheck['message'])->toContain('composer.json');
});

it('dry-runs developer tooling packages as dev requirements', function (): void {
    $temporaryDirectory = storage_path('framework/testing/installer-preflight-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $argumentsPath = $temporaryDirectory . '/composer-arguments.txt';
    $fakeComposerPath = $temporaryDirectory . '/composer';
    File::put($fakeComposerPath, "#!/bin/sh\nprintf '%s\\n' \"$@\" >> '{$argumentsPath}'\nprintf '%s\\n' '---' >> '{$argumentsPath}'\nexit 0\n");
    chmod($fakeComposerPath, 0755);

    config(['capell-installer.composer_binary' => $fakeComposerPath]);

    try {
        $report = resolve(InstallerPreflight::class)->run(new InstallInputData(
            siteUrl: 'https://example.test',
            packages: [],
            languages: [],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            installDeveloperTooling: true,
        ));

        $developerToolingCheck = installerPreflightCheck($report, 'developer-tooling-packages');
        $arguments = File::get($argumentsPath);

        expect($developerToolingCheck)
            ->toMatchArray([
                'label' => 'Developer tooling package dry-run',
                'status' => 'pass',
            ])
            ->and($arguments)->toContain("require\n")
            ->and($arguments)->toContain("capell-app/agent-bridge:*\n")
            ->and($arguments)->toContain("laravel/boost:*\n")
            ->and($arguments)->toContain("--dev\n")
            ->and($arguments)->not->toContain('--repository')
            ->and($arguments)->not->toContain("config\n");
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.composer_binary' => 'composer']);
    }
});

it('fails selected package dry-runs when composer is required but missing', function (): void {
    config(['capell-installer.composer_binary' => '/missing/composer']);

    $report = resolve(InstallerPreflight::class)->run(new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: [],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: ['vendor/package'],
    ));

    $composerCheck = installerPreflightCheck($report, 'composer-binary');
    $selectedPackagesCheck = installerPreflightCheck($report, 'selected-packages');

    expect($report['status'])->toBe('fail')
        ->and($composerCheck)->toMatchArray([
            'status' => 'fail',
            'severity' => 'blocking',
        ])
        ->and($selectedPackagesCheck)->toMatchArray([
            'status' => 'fail',
            'message' => 'Composer is required to install selected downloadable packages.',
        ]);
});

it('reports cleaned composer dry-run failures', function (): void {
    $temporaryDirectory = storage_path('framework/testing/installer-preflight-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $fakeComposerPath = $temporaryDirectory . '/composer';
    $longError = str_repeat('dependency conflict ', 50);
    File::put($fakeComposerPath, "#!/bin/sh\nprintf '\\033[31m%s\\033[0m\\n' '{$longError}' >&2\nexit 2\n");
    chmod($fakeComposerPath, 0755);

    config(['capell-installer.composer_binary' => $fakeComposerPath]);

    try {
        $report = resolve(InstallerPreflight::class)->run(new InstallInputData(
            siteUrl: 'https://example.test',
            packages: [],
            languages: [],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            extraPackages: ['vendor/package'],
        ));

        $selectedPackagesCheck = installerPreflightCheck($report, 'selected-packages');

        expect($selectedPackagesCheck)->toMatchArray([
            'status' => 'fail',
            'remediation' => 'Check package names, Composer repositories, GitHub access, and HTTPS/SSH clone configuration.',
        ])
            ->and($selectedPackagesCheck['message'])->not->toContain("\033")
            ->and(strlen((string) $selectedPackagesCheck['message']))->toBeLessThanOrEqual(600)
            ->and($selectedPackagesCheck['message'])->toEndWith('...');
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.composer_binary' => 'composer']);
    }
});
