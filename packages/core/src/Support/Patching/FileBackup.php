<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

final readonly class FileBackup
{
    public function __construct(private string $filePath) {}

    public function create(): string
    {
        $backupBaseDir = sys_get_temp_dir() . '/capell/php-file-backups';
        try {
            if (function_exists('storage_path')) {
                $backupBaseDir = storage_path('capell/php-file-backups');
            }
        } catch (Throwable) {
            // Fall back to temp directory if storage_path fails
        }

        $baseBackupDir = $backupBaseDir . '/' . Date::now()->format('Y-m-d-His');
        $backupDir = $baseBackupDir;
        $suffix = 0;
        while (! @mkdir($backupDir, 0700, true)) {
            throw_unless(is_dir($backupDir), RuntimeException::class, 'Failed to create PHP backup directory: ' . $backupDir);
            $backupDir = $baseBackupDir . '-' . ++$suffix;
        }

        $backupPath = $backupDir . '/' . basename($this->filePath);
        $copied = @copy($this->filePath, $backupPath);

        throw_unless(
            $copied,
            RuntimeException::class,
            'Failed to back up PHP file to path: ' . $backupPath,
        );

        return $backupPath;
    }
}
