<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup;

use RuntimeException;

final class BackupTemporaryFiles
{
    /** @var list<string> */
    private array $paths = [];

    public function __construct(private readonly string $directory = '') {}

    public function __destruct()
    {
        $this->cleanup();
    }

    public function create(string $prefix = 'capell-backup-'): string
    {
        $directory = $this->directory !== '' ? $this->directory : sys_get_temp_dir();

        throw_if(! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory), RuntimeException::class, 'Unable to create the backup temporary directory.');

        throw_if(preg_match('/\A[A-Za-z0-9_-]+\z/', $prefix) !== 1, RuntimeException::class, 'The backup temporary file prefix is invalid.');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16));
            $handle = fopen($path, 'x+b');

            if ($handle === false) {
                continue;
            }

            fclose($handle);
            chmod($path, 0600);
            $this->paths[] = $path;

            return $path;
        }

        throw new RuntimeException('Unable to create a backup temporary file.');
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->paths = [];
    }
}
