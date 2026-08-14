<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallPreflightChecksAction;
use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Enums\Install\InstallReadinessStatus;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;
use Illuminate\Support\Facades\File;

function runInstallPreflightInput(string $siteUrl = 'https://example.test'): InstallInputData
{
    return new InstallInputData(
        siteUrl: $siteUrl,
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

it('runs environment checks before the install mutates the application', function (): void {
    $reporter = new RecordingInstallProgressReporter;

    RunInstallPreflightChecksAction::run(
        new InstallInputData(
            siteUrl: 'https://example.test',
            packages: [],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        $reporter,
    );

    expect($reporter->lines)
        ->toContain('✓ PHP runtime and required extensions are available.')
        ->toContain('✓ Composer, cache, storage, and database paths are ready.')
        ->toContain('✓ Database driver configuration is available.')
        ->toContain('Preflight checks passed.');
});

it('blocks installation when the site URL is invalid', function (): void {
    expect(fn (): mixed => RunInstallPreflightChecksAction::run(
        new InstallInputData(
            siteUrl: 'not-a-url',
            packages: [],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        new RecordingInstallProgressReporter,
    ))->toThrow(RuntimeException::class, 'Install preflight failed');
});

it('reports missing installation root paths as blocking filesystem checks', function (): void {
    $originalBasePath = base_path();
    $temporaryBasePath = sys_get_temp_dir() . '/capell-preflight-' . bin2hex(random_bytes(8));
    File::makeDirectory($temporaryBasePath);
    app()->setBasePath($temporaryBasePath);

    try {
        $report = RunInstallPreflightChecksAction::make()->report(runInstallPreflightInput());
    } finally {
        app()->setBasePath($originalBasePath);
        File::deleteDirectory($temporaryBasePath);
    }

    $filesystemCheck = collect($report->checks)->firstWhere('key', 'filesystem');

    expect($filesystemCheck)->not->toBeNull();

    $filesystemCheck = expectPresent($filesystemCheck);

    expect($filesystemCheck)
        ->and($filesystemCheck->status)->toBe(InstallReadinessStatus::Blocked)
        ->and($filesystemCheck->message)->toContain('composer.json is missing or unreadable.')
        ->and($filesystemCheck->message)->toContain('does not exist.');
});

it('reports existing but non-writable installation root paths', function (): void {
    $originalBasePath = base_path();
    $temporaryBasePath = sys_get_temp_dir() . '/capell-preflight-' . bin2hex(random_bytes(8));
    $paths = [
        $temporaryBasePath . '/bootstrap/cache',
        $temporaryBasePath . '/storage',
        $temporaryBasePath . '/database',
    ];

    File::makeDirectory($temporaryBasePath);
    foreach ($paths as $path) {
        File::makeDirectory($path, 0755, true);
        chmod($path, 0555);
    }

    app()->setBasePath($temporaryBasePath);

    try {
        $report = RunInstallPreflightChecksAction::make()->report(runInstallPreflightInput());
    } finally {
        app()->setBasePath($originalBasePath);
        foreach ($paths as $path) {
            chmod($path, 0755);
        }

        File::deleteDirectory($temporaryBasePath);
    }

    $filesystemCheck = collect($report->checks)->firstWhere('key', 'filesystem');
    $filesystemCheck = expectPresent($filesystemCheck);

    expect($filesystemCheck->message)->toContain('is not writable.');
});

it('reports missing, unsupported, and extension-incomplete database configuration', function (): void {
    $originalDatabaseDefault = config('database.default');
    $originalDatabaseConnections = config('database.connections');

    try {
        config([
            'database.default' => 'missing_connection',
            'database.connections.missing_connection' => null,
        ]);

        $report = RunInstallPreflightChecksAction::make()->report(runInstallPreflightInput());
        $databaseCheck = expectPresent(collect($report->checks)->firstWhere('key', 'database-configuration'));

        expect($databaseCheck->message)->toBe('A default database connection and driver must be configured.');

        config([
            'database.default' => 'unsupported_connection',
            'database.connections.unsupported_connection.driver' => 'sqlsrv',
        ]);

        $report = RunInstallPreflightChecksAction::make()->report(runInstallPreflightInput());
        $databaseCheck = expectPresent(collect($report->checks)->firstWhere('key', 'database-configuration'));

        expect($databaseCheck->message)->toBe('Database driver [sqlsrv] is not supported.');

        $platform = Mockery::mock(DatabasePlatform::class);
        $platform->shouldReceive('drivers')->once()->andReturn(['mysql']);
        $platform->shouldReceive('phpExtension')->once()->andReturn('capell_missing_extension');
        $originalRegistry = resolve(DatabasePlatformRegistry::class);
        app()->instance(DatabasePlatformRegistry::class, new DatabasePlatformRegistry([$platform]));
        CapellDatabase::clearResolvedInstance(DatabasePlatformRegistry::class);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
        ]);

        try {
            $report = RunInstallPreflightChecksAction::make()->report(runInstallPreflightInput());
            $databaseCheck = expectPresent(collect($report->checks)->firstWhere('key', 'database-configuration'));
        } finally {
            app()->instance(DatabasePlatformRegistry::class, $originalRegistry);
            CapellDatabase::clearResolvedInstance(DatabasePlatformRegistry::class);
        }

        expect($databaseCheck->message)->toBe('Database driver [mysql] requires PHP extension [capell_missing_extension].');
    } finally {
        config([
            'database.default' => $originalDatabaseDefault,
            'database.connections' => $originalDatabaseConnections,
        ]);
    }
});
