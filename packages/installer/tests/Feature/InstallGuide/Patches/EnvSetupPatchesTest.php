<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AbstractEnvKeyPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Illuminate\Support\Facades\File;

function withInstallGuideEnv(string $contents, Closure $verify): void
{
    $directory = sys_get_temp_dir() . '/capell-install-guide-env-' . bin2hex(random_bytes(8));
    File::makeDirectory($directory);

    try {
        $path = $directory . '/.env';
        File::put($path, $contents);
        $verify($path);
    } finally {
        File::deleteDirectory($directory);
    }
}

it('reports whether an environment setting needs patching', function (
    string $patchClass,
    string $contents,
    PatchStatus $status,
): void {
    /** @var class-string<AbstractEnvKeyPatch> $patchClass */
    withInstallGuideEnv($contents, function (string $path) use ($patchClass, $status): void {
        expect(new $patchClass($path)->probe())->toBe($status);
    });
})->with([
    'queue key missing' => [EnvQueueConnectionPatch::class, "APP_NAME=TestApp\n", PatchStatus::Applicable],
    'synchronous queue' => [EnvQueueConnectionPatch::class, "QUEUE_CONNECTION=sync\n", PatchStatus::Applicable],
    'database queue configured' => [EnvQueueConnectionPatch::class, "QUEUE_CONNECTION=database\n", PatchStatus::AlreadyApplied],
    'settings cache key missing' => [EnvSettingsCachePatch::class, "APP_NAME=TestApp\n", PatchStatus::Applicable],
    'settings cache enabled' => [EnvSettingsCachePatch::class, "SETTINGS_CACHE_ENABLED=true\n", PatchStatus::AlreadyApplied],
    'settings cache explicitly disabled' => [EnvSettingsCachePatch::class, "SETTINGS_CACHE_ENABLED=false\n", PatchStatus::Customised],
]);

it('offers default environment patches only for an existing file', function (): void {
    withInstallGuideEnv('', function (string $path): void {
        File::delete($path);

        $patches = [
            'env-queue-connection-patch' => new EnvQueueConnectionPatch($path),
            'env-settings-cache-patch' => new EnvSettingsCachePatch($path),
        ];

        foreach ($patches as $id => $patch) {
            expect($patch->id())->toBe($id)
                ->and($patch->group())->toBe('environment')
                ->and($patch->defaultEnabled())->toBeTrue()
                ->and($patch->docUrl())->toBeNull()
                ->and($patch->reason())->toBeNull()
                ->and($patch->probe())->toBe(PatchStatus::Unsupported);
        }
    });
});

it('writes each environment default once while preserving existing settings', function (): void {
    withInstallGuideEnv("APP_NAME=TestApp\nAPP_DEBUG=false\n", function (string $path): void {
        new EnvQueueConnectionPatch($path)->apply();
        new EnvSettingsCachePatch($path)->apply();

        $contents = File::get($path);

        expect($contents)->toContain('APP_NAME=TestApp', 'APP_DEBUG=false')
            ->toContain('QUEUE_CONNECTION=database', 'SETTINGS_CACHE_ENABLED=true')
            ->and(substr_count($contents, 'QUEUE_CONNECTION='))->toBe(1)
            ->and(substr_count($contents, 'SETTINGS_CACHE_ENABLED='))->toBe(1);
    });
});

it('refuses to overwrite a configured environment setting', function (
    string $patchClass,
    string $contents,
): void {
    /** @var class-string<AbstractEnvKeyPatch> $patchClass */
    withInstallGuideEnv($contents, function (string $path) use ($patchClass, $contents): void {
        $patch = new $patchClass($path);

        expect(fn () => $patch->apply())->toThrow(RuntimeException::class)
            ->and(File::get($path))->toBe($contents);
    });
})->with([
    'Redis queue configured' => [EnvQueueConnectionPatch::class, "QUEUE_CONNECTION=redis\n"],
    'settings cache enabled' => [EnvSettingsCachePatch::class, "SETTINGS_CACHE_ENABLED=true\n"],
]);
