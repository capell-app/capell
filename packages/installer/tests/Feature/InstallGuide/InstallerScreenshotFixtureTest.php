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

it('refuses to replace an existing incomplete installer environment', function (): void {
    $directory = storage_path('framework/testing/installer-screenshot');
    File::ensureDirectoryExists($directory);
    $path = $directory . '/.env';
    File::put($path, "SETTINGS_CACHE_ENABLED=false\n");
    try {
        expect(fn () => InstallerScreenshotFixture::initialize($path))->toThrow(RuntimeException::class)
            ->and(File::get($path))->toBe("SETTINGS_CACHE_ENABLED=false\n");
    } finally {
        File::deleteDirectory($directory);
    }
});
