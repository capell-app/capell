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
            $this->content = preg_replace($pattern, sprintf('%s=%s', $key, $value), $this->content) ?? $this->content;
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

    public function save(): void
    {
        $bytesWritten = @file_put_contents($this->filePath, $this->content);

        throw_if(
            $bytesWritten === false || $bytesWritten !== strlen($this->content),
            RuntimeException::class,
            'Failed to write environment file at path: ' . $this->filePath,
        );
    }

    public function backup(): string
    {
        $backupDir = storage_path('capell/install-guide-backups/' . Date::now()->format('Y-m-d-His'));
        if (! is_dir($backupDir)) {
            $created = @mkdir($backupDir, 0755, true);

            throw_unless(
                $created || is_dir($backupDir),
                RuntimeException::class,
                'Failed to create environment backup directory: ' . $backupDir,
            );
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
