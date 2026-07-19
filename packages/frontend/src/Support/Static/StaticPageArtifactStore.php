<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Static;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

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

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function writeManifest(array $manifest): void
    {
        File::ensureDirectoryExists($this->root());
        File::put($this->manifestPath(), JsonCodec::encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function readManifest(): array
    {
        $default = ['generated_at' => null, 'artifacts' => []];

        if (! File::exists($this->manifestPath())) {
            return $default;
        }

        return JsonCodec::decodeObject(File::get($this->manifestPath()), $default);
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
