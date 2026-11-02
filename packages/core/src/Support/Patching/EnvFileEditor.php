<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Illuminate\Support\Facades\Date;
use RuntimeException;

final class EnvFileEditor
{
    private readonly string $filePath;

    private string $content;

    public function __construct(string $filePath)
    {
        throw_if(is_link($filePath), UnsafeEnvironmentFileException::class, 'The environment file must not be a symbolic link.');
        throw_unless(file_exists($filePath), RuntimeException::class, 'File does not exist at path: ' . $filePath);

        $this->filePath = $filePath;
        $fileContents = file_get_contents($filePath);
        $this->content = $fileContents !== false ? $fileContents : '';
    }

    public function set(string $key, string $value): self
    {
        $escapedKey = preg_quote($key, '/');
        $pattern = sprintf('/^%s=.*$/m', $escapedKey);

        if (preg_match($pattern, $this->content)) {
            $replacement = sprintf('%s=%s', $key, $value);
            $this->content = preg_replace_callback($pattern, fn (): string => $replacement, $this->content) ?? $this->content;
        } else {
            $this->content .= sprintf('%s%s=%s%s', PHP_EOL, $key, $value, PHP_EOL);
        }

        return $this;
    }

    public function get(string $key): ?string
    {
        $escapedKey = preg_quote($key, '/');
        $pattern = sprintf('/^%s=(.*)$/m', $escapedKey);

        if (preg_match($pattern, $this->content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function save(): void
    {
        throw_if(is_link($this->filePath), UnsafeEnvironmentFileException::class, 'The environment file must not be a symbolic link.');
        $temporary = tempnam(dirname($this->filePath), '.capell-env-');
        throw_if($temporary === false, RuntimeException::class, 'Failed to create a temporary environment file.');
        try {
            $permissions = fileperms($this->filePath);
            throw_if($permissions === false || ! chmod($temporary, $permissions & 0777), RuntimeException::class, 'Failed to preserve environment file permissions.');
            $written = file_put_contents($temporary, $this->content);
            throw_if($written !== strlen($this->content), RuntimeException::class, 'Failed to write the environment file.');
            throw_if(is_link($this->filePath), UnsafeEnvironmentFileException::class, 'The environment file must not be a symbolic link.');
            throw_unless(rename($temporary, $this->filePath), RuntimeException::class, 'Failed to replace the environment file.');
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function backup(): string
    {
        $baseBackupDir = storage_path('capell/install-guide-backups/' . Date::now()->format('Y-m-d-His'));
        $backupDir = $baseBackupDir;
        $suffix = 0;

        while (! @mkdir($backupDir, 0700, true)) {
            throw_unless(is_dir($backupDir), RuntimeException::class, 'Failed to create environment backup directory: ' . $backupDir);
            $backupDir = $baseBackupDir . '-' . ++$suffix;
        }

        $backupPath = $backupDir . '/.env';
        $copied = @copy($this->filePath, $backupPath);

        throw_unless(
            $copied,
            RuntimeException::class,
            'Failed to back up environment file to path: ' . $backupPath,
        );

        return $backupDir;
    }
}
