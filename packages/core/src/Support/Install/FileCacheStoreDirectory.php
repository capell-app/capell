<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use ErrorException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

final class FileCacheStoreDirectory
{
    public function __construct(private readonly Filesystem $files) {}

    public function ensureDefaultStoreDirectoryExists(): void
    {
        $path = $this->defaultFileCachePath();

        if ($path === null) {
            return;
        }

        $this->files->ensureDirectoryExists($path);
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->retryAfterMissingDirectoryFailure(
            fn (): bool => Cache::put($key, $value, $seconds),
        );
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public function retryAfterMissingDirectoryFailure(callable $callback): mixed
    {
        $this->ensureDefaultStoreDirectoryExists();

        try {
            return $callback();
        } catch (ErrorException $errorException) {
            throw_unless($this->isMissingDirectoryWriteFailure($errorException), $errorException);

            $this->ensureDefaultStoreDirectoryExists();

            return $callback();
        }
    }

    private function defaultFileCachePath(): ?string
    {
        $store = (string) config('cache.default');

        if ((string) config(sprintf('cache.stores.%s.driver', $store)) !== 'file') {
            return null;
        }

        $path = config(sprintf('cache.stores.%s.path', $store));

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function isMissingDirectoryWriteFailure(ErrorException $exception): bool
    {
        return str_contains($exception->getMessage(), 'file_put_contents')
            && str_contains($exception->getMessage(), 'No such file or directory');
    }
}
