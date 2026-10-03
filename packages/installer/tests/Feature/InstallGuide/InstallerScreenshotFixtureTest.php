<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Workbench\App\Support\InstallerScreenshotFixture;

uses(CreatesAdminUser::class);

it('provides real applicable and completed install guide probes', function (): void {
    $directory = storage_path('framework/testing/installer-screenshot');
    File::ensureDirectoryExists($directory);
    $path = $directory . '/.env';
    try {
        InstallerScreenshotFixture::initialize($path);
        $contents = File::get($path);
        test()->actingAsAdmin();
        resolve(PatchRegistry::class)
            ->register(new EnvQueueConnectionPatch($path))
            ->register(new EnvSettingsCachePatch($path));
        Livewire::test(InstallGuidePage::class)
            ->assertSuccessful()
            ->assertSee(PatchStatus::AlreadyApplied->getLabel())
            ->assertSee(PatchStatus::Applicable->getLabel())
            ->assertSee('patch-env-settings-cache-patch', false);
        InstallerScreenshotFixture::initialize($path);
        expect(File::get($path))->toBe($contents)
            ->and(new EnvQueueConnectionPatch($path)->probe())->toBe(PatchStatus::Applicable)
            ->and(new EnvSettingsCachePatch($path)->probe())->toBe(PatchStatus::AlreadyApplied);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses to replace an existing customised installer environment', function (string $value): void {
    $directory = storage_path('framework/testing/installer-screenshot');
    File::ensureDirectoryExists($directory);
    $path = $directory . '/.env';
    $contents = sprintf('SETTINGS_CACHE_ENABLED=%s%s', $value, PHP_EOL);
    File::put($path, $contents);
    try {
        expect(fn () => InstallerScreenshotFixture::initialize($path))->toThrow(RuntimeException::class)
            ->and(File::get($path))->toBe($contents);
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['disabled' => ['false'], 'custom value' => ['custom']]);

it('applies the real settings cache patch to an installed host and keeps its backup idempotent', function (): void {
    $originalStorage = storage_path();
    $directory = sys_get_temp_dir() . '/installer-screenshot-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    $path = $directory . '/.env';
    $contents = "APP_NAME=InstalledHost\nAPP_DEBUG=false\nQUEUE_CONNECTION=database\n";
    File::put($path, $contents);
    app()->useStoragePath($directory . '/storage');

    try {
        expect(new EnvSettingsCachePatch($path)->probe())->toBe(PatchStatus::Applicable);
        InstallerScreenshotFixture::initialize($path);
        $patched = File::get($path);
        $backups = File::glob(storage_path('capell/install-guide-backups/*/.env'));
        expect(new EnvSettingsCachePatch($path)->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and($patched)->toStartWith($contents)
            ->and(substr_count($patched, 'SETTINGS_CACHE_ENABLED=true'))->toBe(1)
            ->and($backups)->toHaveCount(1)
            ->and(File::get($backups[0]))->toBe($contents);

        InstallerScreenshotFixture::initialize($path);
        expect(File::get($path))->toBe($patched)
            ->and(File::glob(storage_path('capell/install-guide-backups/*/.env')))->toBe($backups);
    } finally {
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($directory);
    }
});

it('preserves an unreadable installer environment', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'installer-unreadable-');
    $contents = "APP_NAME=InstalledHost\n";
    File::put($path, $contents);
    chmod($path, 0000);

    try {
        expect(fn () => InstallerScreenshotFixture::initialize($path))->toThrow(RuntimeException::class);
    } finally {
        chmod($path, 0600);
        expect(File::get($path))->toBe($contents);
        File::delete($path);
    }
});
