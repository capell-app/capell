<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LockdownStaticCacheSwitcher
{
    private const string MARKER_FILE = '.capell-lockdown-cache.json';

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return array<string, mixed>
     */
    public function activate(): array
    {
        $root = $this->pageCacheRoot();
        $preservedRoot = null;

        if ($this->files->exists($this->markerPath($root))) {
            return $this->state($root, null, alreadyActive: true);
        }

        if ($this->files->exists($root)) {
            $preservedRoot = $this->preservedRoot($root);
            $this->files->move($root, $preservedRoot);
        }

        $this->files->makeDirectory($root, 0755, true);

        $html = $this->lockdownHtml();
        $this->files->put($root . DIRECTORY_SEPARATOR . 'index.html', $html);

        if ($preservedRoot !== null) {
            $this->mirrorCachedHtmlPaths($preservedRoot, $root, $html);
        }

        $state = $this->state($root, $preservedRoot, alreadyActive: false);
        $this->files->put($this->markerPath($root), JsonCodec::encode($state, JSON_PRETTY_PRINT));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $lockdownData
     */
    public function deactivate(array $lockdownData): void
    {
        $staticCache = $lockdownData['static_cache'] ?? [];

        if (! is_array($staticCache)) {
            return;
        }

        $root = $this->stringValue($staticCache['root'] ?? null) ?? $this->pageCacheRoot();
        $preservedRoot = $this->stringValue($staticCache['preserved_root'] ?? null);

        if ($this->files->exists($this->markerPath($root))) {
            $this->files->deleteDirectory($root);
        }

        if ($preservedRoot !== null && $this->files->exists($preservedRoot) && ! $this->files->exists($root)) {
            $this->files->move($preservedRoot, $root);
        }
    }

    private function pageCacheRoot(): string
    {
        $configured = config('filesystems.disks.page_cache.root');

        return is_string($configured) && $configured !== ''
            ? $configured
            : public_path('page-cache');
    }

    private function preservedRoot(string $root): string
    {
        return $root . '.capell-live-' . Date::now()->format('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    private function markerPath(string $root): string
    {
        return $root . DIRECTORY_SEPARATOR . self::MARKER_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $root, ?string $preservedRoot, bool $alreadyActive): array
    {
        return [
            'root' => $root,
            'preserved_root' => $preservedRoot,
            'already_active' => $alreadyActive,
            'marker' => $this->markerPath($root),
        ];
    }

    private function mirrorCachedHtmlPaths(string $sourceRoot, string $targetRoot, string $html): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $cachedFile) {
            if (! $cachedFile instanceof SplFileInfo) {
                continue;
            }

            if (! $cachedFile->isFile()) {
                continue;
            }

            if ($cachedFile->getExtension() !== 'html') {
                continue;
            }

            $relativePath = ltrim(str_replace($sourceRoot, '', $cachedFile->getPathname()), DIRECTORY_SEPARATOR);
            $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;

            $this->files->ensureDirectoryExists(dirname($targetPath));
            $this->files->put($targetPath, $html);
        }
    }

    private function lockdownHtml(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Service unavailable</title></head><body><main><h1>Service unavailable</h1><p>This site is temporarily unavailable.</p></main></body></html>';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
