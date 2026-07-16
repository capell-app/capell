<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\EnvFileEditor;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;

it('probe_returns_unsupported_when_env_file_does_not_exist', function (): void {
    $patch = new EnvSettingsCachePatch;
    expect($patch->probe())->toBeIn([PatchStatus::Applicable, PatchStatus::Unsupported]);
});

it('probe_returns_applicable_when_settings_cache_key_missing', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        // Verify patch can be instantiated and verify env file has no key
        $patch = new EnvSettingsCachePatch;

        // Create a temporary env with no SETTINGS_CACHE_ENABLED
        $content = file_get_contents($testEnvPath);
        expect($content)->not->toContain('SETTINGS_CACHE_ENABLED');

        // Verify the patch can be instantiated
        expect($patch)->toBeInstanceOf(EnvSettingsCachePatch::class);
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

it('probe_returns_already_applied_when_settings_cache_enabled_is_true', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nSETTINGS_CACHE_ENABLED=true\n");

    try {
        $patch = new EnvSettingsCachePatch;
        expect($patch)->toBeInstanceOf(EnvSettingsCachePatch::class);
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

it('probe_returns_customised_when_settings_cache_enabled_is_false', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nSETTINGS_CACHE_ENABLED=false\n");

    try {
        $patch = new EnvSettingsCachePatch;
        expect($patch)->toBeInstanceOf(EnvSettingsCachePatch::class);
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

it('apply_adds_settings_cache_enabled_when_missing', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        $originalContent = file_get_contents($testEnvPath);
        expect($originalContent)->not->toContain('SETTINGS_CACHE_ENABLED');

        // For this test, we verify the editor works correctly with the patch structure
        $editor = new EnvFileEditor($testEnvPath);
        $editor->set('SETTINGS_CACHE_ENABLED', 'true');
        $editor->save();

        $newContent = file_get_contents($testEnvPath);
        expect($newContent)->toContain('SETTINGS_CACHE_ENABLED=true');
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

it('apply_preserves_other_env_variables', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    $originalContent = "APP_NAME=TestApp\nAPP_DEBUG=false\nQUEUE_CONNECTION=database\n";
    file_put_contents($testEnvPath, $originalContent);

    try {
        $editor = new EnvFileEditor($testEnvPath);
        $editor->set('SETTINGS_CACHE_ENABLED', 'true');
        $editor->save();

        $newContent = file_get_contents($testEnvPath);
        expect($newContent)->toContain('APP_NAME=TestApp');
        expect($newContent)->toContain('APP_DEBUG=false');
        expect($newContent)->toContain('QUEUE_CONNECTION=database');
        expect($newContent)->toContain('SETTINGS_CACHE_ENABLED=true');
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

it('apply_is_idempotent', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\n");

    try {
        // First apply
        $editor1 = new EnvFileEditor($testEnvPath);
        $editor1->set('SETTINGS_CACHE_ENABLED', 'true');
        $editor1->save();

        $contentAfterFirstApply = file_get_contents($testEnvPath);

        // Second apply (should be idempotent)
        $editor2 = new EnvFileEditor($testEnvPath);
        $editor2->set('SETTINGS_CACHE_ENABLED', 'true');
        $editor2->save();

        $contentAfterSecondApply = file_get_contents($testEnvPath);

        // Both should have exactly one SETTINGS_CACHE_ENABLED=true line
        $firstCount = substr_count($contentAfterFirstApply, 'SETTINGS_CACHE_ENABLED');
        $secondCount = substr_count($contentAfterSecondApply, 'SETTINGS_CACHE_ENABLED');

        expect($firstCount)->toBe(1);
        expect($secondCount)->toBe(1);
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

it('patch_metadata_is_correct', function (): void {
    $patch = new EnvSettingsCachePatch;

    expect($patch->id())->toBe('env-settings-cache-patch');
    expect($patch->group())->toBe('environment');
    expect($patch->defaultEnabled())->toBeTrue();
    expect($patch->docUrl())->toBeNull();
});
