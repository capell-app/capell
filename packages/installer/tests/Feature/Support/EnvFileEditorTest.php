<?php

declare(strict_types=1);

use Capell\Installer\Support\Patching\EnvFileEditor;

test('sets_new_env_variable', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        $editor = new EnvFileEditor($testEnvPath);
        $editor->set('QUEUE_CONNECTION', 'database')->save();

        $content = file_get_contents($testEnvPath);
        expect($content)->toContain('QUEUE_CONNECTION=database');
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

test('updates_existing_env_variable', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        $editor = new EnvFileEditor($testEnvPath);
        $editor->set('APP_DEBUG', 'true')->save();

        $content = file_get_contents($testEnvPath);
        expect($content)->toContain('APP_DEBUG=true');
        expect($content)->not->toContain('APP_DEBUG=false');
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

test('gets_env_variable', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        $editor = new EnvFileEditor($testEnvPath);

        expect($editor->get('APP_NAME'))->toBe('TestApp');
        expect($editor->get('APP_DEBUG'))->toBe('false');
        expect($editor->get('UNKNOWN_VAR'))->toBeNull();
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

test('creates_backup', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        $editor = new EnvFileEditor($testEnvPath);
        $backupPath = $editor->backup();

        expect(is_dir($backupPath))->toBeTrue();
        expect(file_exists($backupPath . '/.env'))->toBeTrue();

        $originalContent = file_get_contents($testEnvPath);
        $backupContent = file_get_contents($backupPath . '/.env');
        expect($backupContent)->toBe($originalContent);

        // Cleanup backup
        unlink($backupPath . '/.env');
        rmdir($backupPath);
        rmdir(dirname($backupPath));
    } finally {
        if (file_exists($testEnvPath)) {
            unlink($testEnvPath);
        }
    }
});

test('throws when environment file save cannot be written', function (): void {
    $testEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    file_put_contents($testEnvPath, "APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        $editor = new EnvFileEditor($testEnvPath);

        chmod($testEnvPath, 0400);

        expect(fn (): null => $editor->set('APP_DEBUG', 'true')->save())
            ->toThrow(RuntimeException::class, 'Failed to write environment file at path');

        expect(file_get_contents($testEnvPath))->toContain('APP_DEBUG=false');
    } finally {
        if (file_exists($testEnvPath)) {
            chmod($testEnvPath, 0600);
            unlink($testEnvPath);
        }
    }
});
