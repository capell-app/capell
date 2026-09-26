<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Static;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

final class StaticPageArtifactStore
{
    public function root(): string
    {
        $configuredPath = config('capell-frontend.static_artifacts_path');

        return is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : storage_path('framework/capell-static-artifacts');
    }

    public function manifestPath(): string
    {
        return $this->root() . '/manifest.json';
    }

    public function putHtml(string $file, string $contents): void
    {
        $path = $this->pathWithinRoot($file);

        $this->writeAtomically($path, $contents);
    }

    public function forgetHtml(string $file): void
    {
        $path = $this->pathWithinRoot($file);

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function writeManifest(array $manifest): void
    {
        File::ensureDirectoryExists($this->root());
        $this->writeAtomically($this->manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function readManifest(): array
    {
        if (! File::exists($this->manifestPath())) {
            return ['generated_at' => null, 'artifacts' => []];
        }

        $decoded = json_decode(File::get($this->manifestPath()), true);

        return is_array($decoded) ? $decoded : ['generated_at' => null, 'artifacts' => []];
    }

    private function writeAtomically(string $path, string $contents): void
    {
        // Keep temporary writes beside the destination so the rename stays on the same filesystem.
        $temporaryPath = dirname($path) . '/.static-' . bin2hex(random_bytes(16));
        $message = __('capell-frontend::messages.static_artifact_write_failed', ['path' => $path]);

        try {
            throw_if(File::put($temporaryPath, $contents) !== strlen($contents), RuntimeException::class, $message);
            throw_unless(File::chmod($temporaryPath, 0666 & ~umask()), RuntimeException::class, $message);
            throw_unless(File::move($temporaryPath, $path), RuntimeException::class, $message);
        } finally {
            if (File::exists($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }
    }

    private function pathWithinRoot(string $file): string
    {
        $relativePath = ltrim($file, '/');

        throw_if($relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || in_array('..', explode('/', $relativePath), true), InvalidArgumentException::class, 'Static artifact path must stay inside the artifact root.');

        $root = $this->root();
        File::ensureDirectoryExists($root);

        $resolvedRootPath = realpath($root);
        $rootPath = $resolvedRootPath !== false ? $resolvedRootPath : $root;
        $path = $rootPath . '/' . $relativePath;
        $directory = dirname($path);
        File::ensureDirectoryExists($directory);
        $resolvedDirectoryPath = realpath($directory);
        $directoryPath = $resolvedDirectoryPath !== false ? $resolvedDirectoryPath : $directory;

        throw_unless(str_starts_with($directoryPath . '/', rtrim($rootPath, '/') . '/'), InvalidArgumentException::class, 'Static artifact path must stay inside the artifact root.');

        return $path;
    }
}
