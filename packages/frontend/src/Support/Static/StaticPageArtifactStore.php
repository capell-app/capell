<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Static;

use Closure;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Finder\Finder;

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

    /** Ownership is private and cumulative; the public manifest is only a receipt. */
    public function putOwnedHtml(string $file, string $contents, int $siteId): void
    {
        $this->withOwnershipLock(function (array $ownership) use ($file, $contents, $siteId): void {
            // Persist before publishing bytes, retaining former owners in case
            // replacement fails and leaves their previous content on disk.
            $this->pathWithinRoot($file);
            throw_unless($siteId > 0, InvalidArgumentException::class, 'Static artifact ownership requires a site.');
            $relative = ltrim($file, '/');
            $ownership[$relative] = array_values(array_unique([...($ownership[$relative] ?? []), $siteId]));
            $this->writeOwnership($ownership);
            $this->putHtml($file, $contents);
        });
    }

    public function ownershipPath(): string
    {
        File::ensureDirectoryExists($this->root());
        $root = realpath($this->root());
        throw_if($root === false, RuntimeException::class, 'Static artifact root cannot be resolved.');
        $directory = storage_path('framework/capell-static-ownership');
        File::ensureDirectoryExists($directory, 0700);
        $resolvedDirectory = realpath($directory);
        throw_if($resolvedDirectory === false || str_starts_with($resolvedDirectory . '/', rtrim($root, '/') . '/'), RuntimeException::class, 'Static artifact ownership must be outside the export root.');

        return $resolvedDirectory . '/' . hash('sha256', $root) . '.json';
    }

    public function purgeSite(int $siteId): void
    {
        $this->withOwnershipLock(function (array $ownership) use ($siteId): void {
            $manifest = $this->readManifest();
            $remove = array_fill_keys(array_keys(array_filter($ownership, fn (array $owners): bool => in_array($siteId, $owners, true))), true);
            // Legacy receipts may have been overwritten by another site's export.
            // No fingerprint can recover ownership: remove untracked managed HTML
            // conservatively rather than certify that unknown files are safe.
            foreach (Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($this->root()) as $file) {
                if (strtolower($file->getExtension()) !== 'html') {
                    continue;
                }

                $relative = $file->getRelativePathname();
                if (! isset($ownership[$relative])) {
                    $remove[$relative] = true;
                }
            }

            foreach (($manifest['artifacts'] ?? []) as $artifact) {
                if (is_string($artifact['file'] ?? null) && ! isset($ownership[ltrim($artifact['file'], '/')])) {
                    $remove[ltrim($artifact['file'], '/')] = true;
                }
            }

            foreach (array_keys($remove) as $file) {
                $this->forgetHtml($file);
                throw_if(File::exists($this->pathWithinRoot($file)), RuntimeException::class, 'A private import export could not be removed.');
                unset($ownership[$file]);
            }

            $manifest['artifacts'] = array_values(array_filter($manifest['artifacts'] ?? [], fn (array $artifact): bool => ! isset($remove[ltrim($artifact['file'] ?? '', '/')])));
            $this->writeManifest($manifest);
            $this->writeOwnership($ownership);
        });
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

    /** @param Closure(array<string, list<int>>): void $callback */
    private function withOwnershipLock(Closure $callback): void
    {
        $path = $this->ownershipPath();
        $lock = fopen($path . '.lock', 'c+');
        throw_if($lock === false, RuntimeException::class, 'Static artifact ownership cannot be locked.');

        try {
            throw_unless(chmod($path . '.lock', 0600) && flock($lock, LOCK_EX), RuntimeException::class, 'Static artifact ownership cannot be locked.');
            $ownership = [];
            if (File::exists($path)) {
                $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
                throw_unless(is_array($decoded) && ($decoded['version'] ?? null) === 1 && is_array($decoded['files'] ?? null), RuntimeException::class, 'Static artifact ownership is invalid.');
                foreach ($decoded['files'] as $file => $owners) {
                    throw_unless(is_string($file) && is_array($owners) && $owners !== [] && array_is_list($owners) && array_all($owners, fn (mixed $owner): bool => is_int($owner) && $owner > 0), RuntimeException::class, 'Static artifact ownership is invalid.');
                    $ownership[$file] = $owners;
                }
            }

            $callback($ownership);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, list<int>> $ownership */
    private function writeOwnership(array $ownership): void
    {
        $this->writeAtomically($this->ownershipPath(), json_encode(['version' => 1, 'files' => $ownership], JSON_THROW_ON_ERROR), 0600);
    }

    private function writeAtomically(string $path, string $contents, ?int $mode = null): void
    {
        // Keep temporary writes beside the destination so the rename stays on the same filesystem.
        $temporaryPath = dirname($path) . '/.static-' . bin2hex(random_bytes(16));
        $message = __('capell-frontend::messages.static_artifact_write_failed', ['path' => $path]);

        try {
            throw_if(File::put($temporaryPath, $contents) !== strlen($contents), RuntimeException::class, $message);
            throw_unless(File::chmod($temporaryPath, $mode ?? (0666 & ~umask())), RuntimeException::class, $message);
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
