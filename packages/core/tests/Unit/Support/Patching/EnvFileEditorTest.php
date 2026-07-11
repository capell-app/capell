<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\EnvFileEditor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

it('reads writes and backs up env files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_env_');
    file_put_contents($path, "APP_NAME=Capell\nQUEUE_CONNECTION=sync\n");

    try {
        $editor = new EnvFileEditor($path);

        expect($editor->get('APP_NAME'))->toBe('Capell')
            ->and($editor->get('MISSING_KEY'))->toBeNull();

        $editor
            ->set('QUEUE_CONNECTION', 'database')
            ->set('CACHE_STORE', 'redis')
            ->save();

        $content = (string) file_get_contents($path);
        expect($content)->toContain('QUEUE_CONNECTION=database')
            ->and($content)->not->toContain('QUEUE_CONNECTION=sync')
            ->and($content)->toContain('CACHE_STORE=redis');

        $backupPath = new EnvFileEditor($path)->backup();
        expect(file_exists($backupPath . '/.env'))->toBeTrue();

        File::deleteDirectory(dirname($backupPath));
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('rejects missing env files', function (): void {
    expect(fn (): mixed => new EnvFileEditor(sys_get_temp_dir() . '/missing-capell-env-' . uniqid()))
        ->toThrow(RuntimeException::class, 'File does not exist at path');
});

it('fails clearly when env writes do not complete', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_env_unwritable_');
    file_put_contents($path, "APP_NAME=Capell\n");

    try {
        $editor = new EnvFileEditor($path);

        unlink($path);
        mkdir($path);

        expect(function () use ($editor): void {
            $editor->set('APP_NAME', 'Changed')->save();
        })
            ->toThrow(RuntimeException::class, 'Failed to write environment file at path: ' . $path);
    } finally {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
});

it('fails clearly when env backups cannot be copied', function (): void {
    Date::setTestNow('2026-06-12 10:11:12');
    $path = tempnam(sys_get_temp_dir(), 'capell_env_backup_');
    file_put_contents($path, "APP_NAME=Capell\n");
    $backupRoot = storage_path('capell/install-guide-backups/2026-06-12-101112');

    try {
        $editor = new EnvFileEditor($path);
        unlink($path);

        expect(fn (): string => $editor->backup())
            ->toThrow(RuntimeException::class, 'Failed to back up environment file to path: ' . $backupRoot . '/.env');
    } finally {
        Date::setTestNow();
        File::deleteDirectory(dirname($backupRoot));

        if (file_exists($path)) {
            unlink($path);
        }
    }
});
