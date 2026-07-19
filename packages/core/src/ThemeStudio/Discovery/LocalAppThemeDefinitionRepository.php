<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Discovery;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class LocalAppThemeDefinitionRepository
{
    public const string CACHE_FILE = 'cache/capell-local-app-themes.php';

    public const string THEME_ROOT = 'views/capell/themes/capell-app';

    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly LocalAppThemeDefinitionMapper $mapper,
    ) {}

    /**
     * @return array<string, ThemeDefinitionData>
     */
    public function all(): array
    {
        $cached = $this->cachedManifestPayloads();

        if ($cached !== null) {
            return $this->definitionsFromPayloads($cached);
        }

        return $this->discover();
    }

    /**
     * @return array<string, ThemeDefinitionData>
     */
    public function discover(): array
    {
        $root = $this->themeRootPath();

        if (! $this->files->isDirectory($root)) {
            return [];
        }

        $definitions = [];

        foreach ($this->files->glob($root . '/*/theme.json') ?: [] as $manifestPath) {
            $definition = $this->definitionFromJsonFile($manifestPath);

            if (! $definition instanceof ThemeDefinitionData) {
                continue;
            }

            $definitions[$definition->key] = $definition;
        }

        ksort($definitions);

        return $definitions;
    }

    public function writeCache(): void
    {
        $payload = collect($this->discover())
            ->map(fn (ThemeDefinitionData $definition): array => $definition->toArray())
            ->all();

        $this->files->ensureDirectoryExists(dirname($this->cachePath()));
        $this->files->replace(
            $this->cachePath(),
            '<?php return ' . var_export($payload, return: true) . ';' . PHP_EOL,
        );
    }

    public function clearCache(): bool
    {
        if (! $this->files->exists($this->cachePath())) {
            return false;
        }

        $this->files->delete($this->cachePath());

        return true;
    }

    public function cachePath(): string
    {
        return $this->app->bootstrapPath(self::CACHE_FILE);
    }

    public function themeRootPath(): string
    {
        return $this->app->resourcePath(self::THEME_ROOT);
    }

    private function definitionFromJsonFile(string $manifestPath): ?ThemeDefinitionData
    {
        try {
            $decoded = JsonCodec::decode($this->files->get($manifestPath));

            throw_unless(is_array($decoded), InvalidArgumentException::class, 'Theme manifest root must be a JSON object.');

            return $this->mapper->fromManifest($decoded);
        } catch (Throwable $throwable) {
            Log::warning('Skipping invalid local Capell app theme manifest.', [
                'path' => $manifestPath,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function cachedManifestPayloads(): ?array
    {
        $cachePath = $this->cachePath();

        if (! $this->files->exists($cachePath)) {
            return null;
        }

        try {
            $payload = require $cachePath;
        } catch (Throwable $throwable) {
            $this->files->delete($cachePath);

            Log::warning('Ignoring invalid local Capell app theme cache file.', [
                'path' => $cachePath,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        $this->files->delete($cachePath);

        Log::warning('Ignoring invalid local Capell app theme cache file.', [
            'path' => $cachePath,
            'message' => 'Cache file did not return an array.',
        ]);

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     * @return array<string, ThemeDefinitionData>
     */
    private function definitionsFromPayloads(array $payloads): array
    {
        $definitions = [];

        foreach ($payloads as $payload) {
            try {
                $definition = $this->mapper->fromManifest($payload);
            } catch (Throwable $exception) {
                Log::warning('Skipping invalid cached local Capell app theme definition.', [
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $definitions[$definition->key] = $definition;
        }

        ksort($definitions);

        return $definitions;
    }
}
