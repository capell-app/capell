<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Illuminate\Support\Facades\File;

function writeEnvSettingsCacheFixture(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'env_settings_cache_');
    File::put($path, $contents);

    return $path;
}

it('probe returns unsupported when the env file does not exist', function (): void {
    $patch = new EnvSettingsCachePatch('/does/not/exist/.env');

    expect($patch->probe())->toBe(PatchStatus::Unsupported);
});

it('probe returns applicable when the key is missing', function (): void {
    $path = writeEnvSettingsCacheFixture("APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        expect(new EnvSettingsCachePatch($path)->probe())->toBe(PatchStatus::Applicable);
    } finally {
        File::delete($path);
    }
});

it('probe returns already applied when the key is true', function (): void {
    $path = writeEnvSettingsCacheFixture("APP_NAME=TestApp\nSETTINGS_CACHE_ENABLED=true\n");

    try {
        expect(new EnvSettingsCachePatch($path)->probe())->toBe(PatchStatus::AlreadyApplied);
    } finally {
        File::delete($path);
    }
});

it('probe returns customised when the key is set to another value', function (): void {
    $path = writeEnvSettingsCacheFixture("APP_NAME=TestApp\nSETTINGS_CACHE_ENABLED=false\n");

    try {
        expect(new EnvSettingsCachePatch($path)->probe())->toBe(PatchStatus::Customised);
    } finally {
        File::delete($path);
    }
});

it('apply adds the key while preserving other env variables', function (): void {
    $path = writeEnvSettingsCacheFixture("APP_NAME=TestApp\nAPP_DEBUG=false\nQUEUE_CONNECTION=database\n");

    try {
        new EnvSettingsCachePatch($path)->apply();

        $contents = File::get($path);

        expect($contents)->toContain('APP_NAME=TestApp')
            ->and($contents)->toContain('APP_DEBUG=false')
            ->and($contents)->toContain('QUEUE_CONNECTION=database')
            ->and($contents)->toContain('SETTINGS_CACHE_ENABLED=true')
            ->and(substr_count($contents, 'SETTINGS_CACHE_ENABLED'))->toBe(1);
    } finally {
        File::delete($path);
    }
});

it('apply throws when the patch is not applicable', function (): void {
    $path = writeEnvSettingsCacheFixture("SETTINGS_CACHE_ENABLED=true\n");

    try {
        expect(fn () => new EnvSettingsCachePatch($path)->apply())->toThrow(RuntimeException::class);
    } finally {
        File::delete($path);
    }
});

it('patch metadata is correct', function (): void {
    $patch = new EnvSettingsCachePatch;

    expect($patch->id())->toBe('env-settings-cache-patch');
    expect($patch->group())->toBe('environment');
    expect($patch->defaultEnabled())->toBeTrue();
    expect($patch->docUrl())->toBeNull();
    expect($patch->reason())->toBeNull();
});
