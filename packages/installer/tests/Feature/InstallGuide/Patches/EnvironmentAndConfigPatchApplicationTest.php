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

function writeInstallerPatchConfigFile(string $relativePath, string $content): void
{
    $path = base_path($relativePath);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $content);
}
