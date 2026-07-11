<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Tailwind;

use Capell\Core\Contracts\RegistersTailwindAssets;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Throwable;

class TailwindAssetsGenerator
{
    public function __construct(private readonly Filesystem $files) {}

    public function collect(): TailwindAssetsRegistry
    {
        return $this->collectWithTarget($this->targetPath());
    }

    /**
     * @return array<int, string>
     */
    public function generate(?string $absoluteBaseTargetPath = null): array
    {
        $targetPath = $this->targetPath($absoluteBaseTargetPath);

        $this->generateFile($targetPath);

        return [$targetPath];
    }

    private function generateFile(string $targetPath): void
    {
        $registry = $this->collectWithTarget($targetPath);

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, $this->renderCss($registry));

        if ($this->shouldValidateSources()) {
            $this->validateSources($registry, $targetPath);
        }
    }

    private function collectWithTarget(string $targetPath): TailwindAssetsRegistry
    {
        $registry = new TailwindAssetsRegistry;

        $this->registerConfigDefaults($registry, $targetPath);
        $this->registerVendorAssets($registry, $targetPath);
        $this->registerProviderAssets($registry);
        $this->registerDefaultThemeColors($registry);

        return $registry;
    }

    private function registerConfigDefaults(TailwindAssetsRegistry $registry, string $targetPath): void
    {
        $config = config('capell-frontend.tailwind', []);
        $origin = 'config:capell-frontend.tailwind';

        $registry
            ->registerImports($this->stringList($config['imports'] ?? []), $origin)
            ->registerPlugins($this->stringList($config['plugins'] ?? []), $origin);

        foreach ($this->stringList($config['sources'] ?? []) as $source) {
            $resolved = Path::isAbsolute($source)
                ? $source
                : $this->resolveAppRelativePath($source);

            $registry->registerSource($this->relativePath($resolved, $targetPath), $origin);
        }
    }

    private function registerVendorAssets(TailwindAssetsRegistry $registry, string $targetPath): void
    {
        $this->installedVendorAssetsForType(VendorAssetEnum::TailwindImport)
            ->each(function (VendorAssetData $asset) use ($registry, $targetPath): void {
                $import = trim($asset->value);

                if ($import === '') {
                    return;
                }

                if ($this->isNodeModuleImport($import, $asset)) {
                    $registry->registerImport($import, $this->originForAsset($asset));

                    return;
                }

                $resolved = Path::isAbsolute($import) ? $import : $this->resolveAssetPath($asset, $import);

                $registry->registerImport($this->relativePath($resolved, $targetPath), $this->originForAsset($asset));
            });

        $this->installedVendorAssetsForType(VendorAssetEnum::TailwindPlugin)
            ->each(fn (VendorAssetData $asset): TailwindAssetsRegistry => $registry->registerPlugin($asset->value, $this->originForAsset($asset)));

        $this->installedVendorAssetsForType(VendorAssetEnum::TailwindSource)
            ->each(function (VendorAssetData $asset) use ($registry, $targetPath): void {
                $source = trim($asset->value);

                if ($source === '') {
                    return;
                }

                $resolved = Path::isAbsolute($source) ? $source : $this->resolveAssetPath($asset, $source);

                $registry->registerSource($this->relativePath($resolved, $targetPath), $this->originForAsset($asset));
            });

        $this->installedVendorAssetsForType(VendorAssetEnum::TailwindThemeColor)
            ->each(function (VendorAssetData $asset) use ($registry): void {
                $colorName = trim($asset->value);
                $colorValue = $asset->secondaryValue !== null ? trim($asset->secondaryValue) : '';

                if ($colorName === '' || $colorValue === '') {
                    return;
                }

                $this->registerThemeColor($registry, $colorName, $colorValue, $this->originForAsset($asset));
            });
    }

    private function registerProviderAssets(TailwindAssetsRegistry $registry): void
    {
        /** @var array<int, object> $providers */
        $providers = app()->getProviders(ServiceProvider::class);

        foreach ($providers as $provider) {
            if (! $provider instanceof RegistersTailwindAssets) {
                continue;
            }

            try {
                $provider->registerTailwindAssets($registry);
            } catch (Throwable $exception) {
                Log::warning('Failed to register Tailwind assets from provider.', [
                    'provider' => $provider::class,
                    'error' => $exception->getMessage(),
                ]);

                throw new RuntimeException(sprintf('Failed to register Tailwind assets from provider [%s].', $provider::class), $exception->getCode(), previous: $exception);
            }
        }
    }

    private function registerDefaultThemeColors(TailwindAssetsRegistry $registry): void
    {
        $registry->registerThemeColors([
            'default-accent' => '#2563eb',
            'default-border' => '#d4d8df',
            'default-ink' => '#172033',
            'default-muted' => '#5f6b7c',
            'default-surface' => '#ffffff',
        ], 'capell-frontend:default-theme');
    }

    private function renderCss(TailwindAssetsRegistry $registry): string
    {
        $lines = collect(['@import "tailwindcss";']);

        $lines = $lines->merge($registry->imports()->map(fn (string $import): string => sprintf('@import "%s";', $import)));

        if ($registry->hasThemeColors()) {
            $lines->push($this->renderThemeWidget($registry->themeColors()));
        }

        $lines = $lines->merge($registry->plugins()->map(fn (string $plugin): string => sprintf('@plugin "%s";', $plugin)));
        $lines = $lines->merge($registry->sources()->map(fn (string $source): string => sprintf('@source "%s";', $source)));

        return $lines->implode(PHP_EOL) . PHP_EOL;
    }

    /** @param Collection<string, string> $colors */
    private function renderThemeWidget(Collection $colors): string
    {
        $inner = $colors
            ->filter(function (string $value, string $name): bool {
                if ($this->isSafeThemeColor($name, $value)) {
                    return true;
                }

                $this->logInvalidThemeColor($name, $value, 'render');

                return false;
            })
            ->map(fn (string $value, string $name): string => sprintf('  --color-%s: %s;', $name, $value))
            ->values()
            ->implode(PHP_EOL);

        return '@theme {' . PHP_EOL . $inner . PHP_EOL . '}';
    }

    private function targetPath(?string $overrideAbsolutePath = null): string
    {
        if (is_string($overrideAbsolutePath) && $overrideAbsolutePath !== '') {
            return $this->normalizeTargetPath($overrideAbsolutePath);
        }

        $configPath = config('capell-frontend.tailwind.output_css');

        throw_if(! is_string($configPath) || $configPath === '', InvalidArgumentException::class, 'Tailwind output CSS path is not configured.');

        if (Path::isAbsolute($configPath)) {
            return $this->normalizeTargetPath($configPath);
        }

        if (str_starts_with($configPath, 'resources/')) {
            return $this->normalizeTargetPath(rtrim(resource_path(''), '/') . '/' . substr($configPath, strlen('resources/')));
        }

        return $this->normalizeTargetPath(rtrim(base_path(''), '/') . '/' . ltrim($configPath, '/'));
    }

    private function normalizeTargetPath(string $path): string
    {
        $normalized = rtrim($path, '/');

        throw_if(
            $normalized === '' || str_contains($normalized, "\0"),
            InvalidArgumentException::class,
            'Tailwind output CSS path is invalid.',
        );

        $targetPath = strtolower(pathinfo($normalized, PATHINFO_EXTENSION)) === 'css'
            ? $normalized
            : $normalized . '/frontend.css';

        $absoluteTargetPath = Path::isAbsolute($targetPath)
            ? Path::canonicalize($targetPath)
            : Path::makeAbsolute($targetPath, $this->projectPath());

        $this->ensureTargetPathStaysInsideProject($absoluteTargetPath);

        return $absoluteTargetPath;
    }

    private function ensureTargetPathStaysInsideProject(string $targetPath): void
    {
        $projectPath = $this->projectPath();
        $realProjectPath = $this->realProjectPath();

        throw_unless(
            Path::isBasePath($projectPath, $targetPath) || Path::isBasePath($realProjectPath, $targetPath),
            InvalidArgumentException::class,
            'Tailwind output CSS path must stay inside the project.',
        );

        $realParentPath = $this->realExistingPath(Path::getDirectory($targetPath));

        throw_unless(
            $realParentPath !== null && Path::isBasePath($realProjectPath, $realParentPath),
            InvalidArgumentException::class,
            'Tailwind output CSS path must stay inside the project.',
        );
    }

    private function projectPath(): string
    {
        return Path::canonicalize(base_path());
    }

    private function realProjectPath(): string
    {
        $realPath = realpath(base_path());

        throw_unless(is_string($realPath), InvalidArgumentException::class, 'Unable to resolve the project path.');

        return Path::canonicalize($realPath);
    }

    private function realExistingPath(string $path): ?string
    {
        $currentPath = Path::canonicalize($path);

        while ($currentPath !== '') {
            $realPath = realpath($currentPath);

            if (is_string($realPath)) {
                return Path::canonicalize($realPath);
            }

            $parentPath = Path::getDirectory($currentPath);

            if ($parentPath === $currentPath) {
                return null;
            }

            $currentPath = $parentPath;
        }

        return null;
    }

    private function relativePath(string $path, string $targetPath): string
    {
        return Path::makeRelative(
            str_replace('\\', '/', $path),
            str_replace('\\', '/', dirname($targetPath)),
        );
    }

    private function shouldValidateSources(): bool
    {
        return (bool) config('capell-frontend.tailwind.validate_sources', false);
    }

    private function validateSources(TailwindAssetsRegistry $registry, string $targetPath): void
    {
        $targetDir = dirname($targetPath);

        foreach ($registry->sources() as $source) {
            $absolute = Path::isAbsolute($source)
                ? $source
                : Path::join($targetDir, $source);

            try {
                $matches = glob($absolute, GLOB_BRACE);
            } catch (Throwable $exception) {
                Log::warning('Failed to validate Tailwind source glob.', [
                    'source' => $source,
                    'path' => $absolute,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $matches) {
                Log::warning('Tailwind source glob did not match any files.', [
                    'source' => $source,
                    'path' => $absolute,
                ]);
            }
        }
    }

    private function resolveAppRelativePath(string $path): string
    {
        if (Path::isAbsolute($path)) {
            return $path;
        }

        if (str_starts_with($path, 'resources/')) {
            return rtrim(resource_path(''), '/') . '/' . substr($path, strlen('resources/'));
        }

        return rtrim(base_path(''), '/') . '/' . ltrim($path, '/');
    }

    private function resolveVendorPackageAbsolute(string $packageName, string $relativePackagePath): string
    {
        return rtrim(base_path(''), '/') . '/vendor/' . $packageName . '/' . ltrim($relativePackagePath, '/');
    }

    /** @return Collection<int, VendorAssetData> */
    private function installedVendorAssetsForType(VendorAssetEnum $type): Collection
    {
        return CapellCore::getVendorAssetsForType($type)
            ->filter(fn (VendorAssetData $asset): bool => $asset->packageName === null || CapellCore::isPackageInstalled($asset->packageName))
            ->values();
    }

    private function originForAsset(VendorAssetData $asset): string
    {
        return $asset->packageName === null ? 'vendor-asset:global' : 'vendor-asset:' . $asset->packageName;
    }

    private function resolveAssetPath(VendorAssetData $asset, string $relativePath): string
    {
        if ($asset->packageName !== null && $asset->packageName !== '') {
            return $this->resolveVendorPackageAbsolute($asset->packageName, $relativePath);
        }

        return $this->resolveAppRelativePath($relativePath);
    }

    private function registerThemeColor(TailwindAssetsRegistry $registry, string $name, string $value, string $origin): void
    {
        if (! $this->isSafeThemeColor($name, $value)) {
            $this->logInvalidThemeColor($name, $value, $origin);

            return;
        }

        $registry->registerThemeColor(trim($name), trim($value), $origin);
    }

    private function isNodeModuleImport(string $import, ?VendorAssetData $asset = null): bool
    {
        $import = ltrim($import);

        if (str_starts_with($import, 'resources/')) {
            return false;
        }

        if (str_starts_with($import, './') || str_starts_with($import, '../') || str_starts_with($import, '/')) {
            return false;
        }

        if ($asset instanceof VendorAssetData && $asset->packageName !== null && $asset->packageName !== '') {
            return true;
        }

        if (preg_match('~^@[a-z0-9_.-]+/[a-z0-9_.-]+(?:/.*)?$~i', $import) === 1) {
            return true;
        }

        return preg_match('~^[a-z0-9_.-]+(?:/.*)?$~i', $import) === 1;
    }

    private function isSafeThemeColor(string $name, string $value): bool
    {
        $name = trim($name);
        $value = trim($value);

        if ($name === '' || $value === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F;{}<>]/', $value) === 1) {
            return false;
        }

        if (preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\([A-Za-z0-9\s.,%\/+\-]*\)$/i', $value) === 1) {
            return true;
        }

        return preg_match('/^(?:black|white|transparent|currentColor|red|green|blue|yellow|orange|purple|pink|gray|grey|indigo|violet|cyan|teal|lime|navy|silver|maroon|olive|aqua|fuchsia)$/i', $value) === 1;
    }

    private function logInvalidThemeColor(string $name, string $value, string $origin): void
    {
        if (! app()->bound('log')) {
            return;
        }

        Log::warning('Skipping invalid Tailwind theme color.', [
            'name' => $name,
            'value' => $value,
            'origin' => $origin,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }
}
