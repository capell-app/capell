<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Capell\Installer\Support\InstallGuide\Patches\FilesystemsPageCacheDiskPatch;
use Capell\Installer\Support\InstallGuide\Patches\LoggingCapellChannelPatch;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-environment-config-patches-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('applies installer environment patches through their real probe and backup flow', function (): void {
    $envPath = base_path('.env');
    File::put($envPath, "APP_NAME=Capell\nQUEUE_CONNECTION=sync\n");

    $queuePatch = new EnvQueueConnectionPatch;
    $settingsPatch = new EnvSettingsCachePatch;

    expect($queuePatch->probe())->toBe(PatchStatus::Applicable)
        ->and($settingsPatch->probe())->toBe(PatchStatus::Applicable);

    $queuePatch->apply();
    $settingsPatch->apply();

    expect(File::get($envPath))->toContain('QUEUE_CONNECTION=database')
        ->toContain('SETTINGS_CACHE_ENABLED=true')
        ->and($queuePatch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and($settingsPatch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(File::directories(storage_path('capell/install-guide-backups')))->not->toBeEmpty();
});

it('applies canonical filesystem and logging config patches without overwriting existing keys', function (): void {
    writeInstallerPatchConfigFile('config/filesystems.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
        ],
    ],
];
PHP);
    writeInstallerPatchConfigFile('config/logging.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
        ],
    ],
];
PHP);

    $filesystemsPatch = new FilesystemsPageCacheDiskPatch;
    $loggingPatch = new LoggingCapellChannelPatch;

    expect($filesystemsPatch->probe())->toBe(PatchStatus::Applicable)
        ->and($loggingPatch->probe())->toBe(PatchStatus::Applicable);

    $filesystemsPatch->apply();
    $loggingPatch->apply();

    expect(File::get(base_path('config/filesystems.php')))
        ->toContain("'page_cache'")
        ->toContain("public_path('page-cache')")
        ->toContain("'local'")
        ->and(File::get(base_path('config/logging.php')))
        ->toContain("'capell'")
        ->toContain("storage_path('logs/capell.log')")
        ->toContain("'stack'")
        ->and($filesystemsPatch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and($loggingPatch->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('marks customised config entries as customised when canonical literals appear elsewhere', function (): void {
    writeInstallerPatchConfigFile('config/filesystems.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'disks' => [
        'page_cache' => [
            'driver' => 's3',
            'root' => storage_path('app/custom-page-cache'),
            'throw' => true,
        ],
        'example' => [
            'driver' => 'local',
            'root' => public_path('page-cache'),
            'throw' => false,
        ],
    ],
];
PHP);
    writeInstallerPatchConfigFile('config/logging.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'channels' => [
        'capell' => [
            'driver' => 'daily',
            'path' => storage_path('logs/custom-capell.log'),
            'level' => 'error',
        ],
        'example' => [
            'driver' => 'single',
            'path' => storage_path('logs/capell.log'),
            'level' => 'debug',
        ],
    ],
];
PHP);

    expect(new FilesystemsPageCacheDiskPatch()->probe())->toBe(PatchStatus::Customised)
        ->and(new LoggingCapellChannelPatch()->probe())->toBe(PatchStatus::Customised);
});

it('recognises canonical target entries regardless of quote style or whitespace', function (): void {
    writeInstallerPatchConfigFile('config/filesystems.php', <<<'PHP'
<?php

return [
    "disks" => [
        "page_cache" => [
            "driver" => "local",
            "root" => public_path( "page-cache" ),
            "throw" => FALSE,
        ],
    ],
];
PHP);
    writeInstallerPatchConfigFile('config/logging.php', <<<'PHP'
<?php

return [
    "channels" => [
        "capell" => [
            "driver" => "single",
            "path" => storage_path( "logs/capell.log" ),
            "level" => "debug",
        ],
    ],
];
PHP);

    expect(new FilesystemsPageCacheDiskPatch()->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(new LoggingCapellChannelPatch()->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('marks filesystem entries customised when any required field differs', function (string $driver, string $root, string $throw): void {
    writeInstallerPatchConfigFile('config/filesystems.php', sprintf(<<<'PHP'
<?php

return [
    'disks' => [
        'page_cache' => [
            'driver' => %s,
            'root' => %s,
            'throw' => %s,
        ],
    ],
];
PHP, $driver, $root, $throw));

    expect(new FilesystemsPageCacheDiskPatch()->probe())->toBe(PatchStatus::Customised);
})->with([
    'driver' => ["'s3'", "public_path('page-cache')", 'false'],
    'root' => ["'local'", "storage_path('app/custom-page-cache')", 'false'],
    'throw' => ["'local'", "public_path('page-cache')", 'true'],
]);

it('marks logging entries customised when any required field differs', function (string $driver, string $path, string $level): void {
    writeInstallerPatchConfigFile('config/logging.php', sprintf(<<<'PHP'
<?php

return [
    'channels' => [
        'capell' => [
            'driver' => %s,
            'path' => %s,
            'level' => %s,
        ],
    ],
];
PHP, $driver, $path, $level));

    expect(new LoggingCapellChannelPatch()->probe())->toBe(PatchStatus::Customised);
})->with([
    'driver' => ["'daily'", "storage_path('logs/capell.log')", "'debug'"],
    'path' => ["'single'", "storage_path('logs/custom-capell.log')", "'debug'"],
    'level' => ["'single'", "storage_path('logs/capell.log')", "'error'"],
]);

it('uses effective duplicate config keys and fields when probing patches', function (): void {
    writeInstallerPatchConfigFile('config/filesystems.php', <<<'PHP'
<?php

return [
    'disks' => [
        'page_cache' => [
            'driver' => 'local',
            'root' => public_path('page-cache'),
            'throw' => false,
        ],
        'page_cache' => [
            'driver' => 'local',
            'root' => public_path('page-cache'),
            'throw' => false,
            'throw' => true,
        ],
    ],
];
PHP);
    writeInstallerPatchConfigFile('config/logging.php', <<<'PHP'
<?php

return [
    'channels' => [
        'capell' => [
            'driver' => 'single',
            'path' => storage_path('logs/capell.log'),
            'level' => 'debug',
        ],
        'capell' => [
            'driver' => 'single',
            'path' => storage_path('logs/capell.log'),
            'level' => 'debug',
            'level' => 'error',
        ],
    ],
];
PHP);

    expect(new FilesystemsPageCacheDiskPatch()->probe())->toBe(PatchStatus::Customised)
        ->and(new LoggingCapellChannelPatch()->probe())->toBe(PatchStatus::Customised);
});

it('rejects unsupported config shapes before applying patches', function (): void {
    $filesystemsPath = tempnam(sys_get_temp_dir(), 'capell_filesystems_');
    $loggingPath = tempnam(sys_get_temp_dir(), 'capell_logging_');

    File::put($filesystemsPath, "<?php\n\nreturn ['disks' => 'local'];\n");
    File::put($loggingPath, "<?php\n\nreturn ['default' => 'stack'];\n");

    try {
        $filesystemsPatch = new FilesystemsPageCacheDiskPatch($filesystemsPath);
        $loggingPatch = new LoggingCapellChannelPatch($loggingPath);

        expect($filesystemsPatch->probe())->toBe(PatchStatus::Unsupported)
            ->and($loggingPatch->probe())->toBe(PatchStatus::Unsupported)
            ->and(function () use ($filesystemsPatch): void {
                $filesystemsPatch->apply();
            })->toThrow(RuntimeException::class, 'Cannot apply patch when status is: unsupported')
            ->and(function () use ($loggingPatch): void {
                $loggingPatch->apply();
            })->toThrow(RuntimeException::class, 'Cannot apply patch when status is: unsupported')
            ->and(File::glob(storage_path('capell/php-file-backups/*/' . basename($filesystemsPath))))->toBeEmpty()
            ->and(File::glob(storage_path('capell/php-file-backups/*/' . basename($loggingPath))))->toBeEmpty();
    } finally {
        File::delete([$filesystemsPath, $loggingPath]);
    }
});

it('applies patches to injected config file paths', function (): void {
    $filesystemsPath = tempnam(sys_get_temp_dir(), 'capell_filesystems_');
    $loggingPath = tempnam(sys_get_temp_dir(), 'capell_logging_');

    File::put($filesystemsPath, "<?php\n\nreturn ['disks' => []];\n");
    File::put($loggingPath, "<?php\n\nreturn ['channels' => []];\n");

    try {
        $filesystemsPatch = new FilesystemsPageCacheDiskPatch($filesystemsPath);
        $loggingPatch = new LoggingCapellChannelPatch($loggingPath);

        expect($filesystemsPatch->probe())->toBe(PatchStatus::Applicable)
            ->and($loggingPatch->probe())->toBe(PatchStatus::Applicable);

        $filesystemsPatch->apply();
        $loggingPatch->apply();

        expect(File::get($filesystemsPath))->toContain("'page_cache'")
            ->toContain("public_path('page-cache')")
            ->and(File::get($loggingPath))->toContain("'capell'")
            ->toContain("storage_path('logs/capell.log')")
            ->and($filesystemsPatch->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and($loggingPatch->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and(File::exists(base_path('config/filesystems.php')))->toBeFalse()
            ->and(File::exists(base_path('config/logging.php')))->toBeFalse()
            ->and(File::glob(storage_path('capell/php-file-backups/*/' . basename($filesystemsPath))))->toBeArray()->not->toBeEmpty()
            ->and(File::glob(storage_path('capell/php-file-backups/*/' . basename($loggingPath))))->toBeArray()->not->toBeEmpty();
    } finally {
        File::delete([$filesystemsPath, $loggingPath]);
    }
});

function writeInstallerPatchConfigFile(string $relativePath, string $content): void
{
    $path = base_path($relativePath);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $content);
}
