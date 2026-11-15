<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class OwnedApplicationPaths
{
    /** Configuration resolved against base_path() when the config loaded, so it still names the shared skeleton. */
    private const array BASE_PATH_CONFIG = [
        'capell.install.welcome_routes_web_path' => 'routes/web.php',
        'capell.install.welcome_env_path' => '.env',
    ];

    public readonly string $root;

    private readonly mixed $allowedRoots;

    /** @var array<string, array<string, string>> */
    private readonly array $publicationGroups;

    /** @var array<string, array<string, string>> */
    private readonly array $publications;

    /** @var array<string, mixed> */
    private readonly array $baseConfig;

    private readonly string $appPath;

    private readonly string $basePath;

    private readonly string $bootstrapPath;

    private readonly string $langPath;

    private readonly string $databasePath;

    private readonly string $publicPath;

    public function __construct(private readonly Application $application)
    {
        // The name keeps "testbench": ClearCachesAction decides whether to skip optimize:clear from the bootstrap path, and the
        // hosted runners fail that command once the path no longer looks like the Testbench harness.
        $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/capell-owned-paths-testbench-' . bin2hex(random_bytes(8));
        $this->allowedRoots = config('capell.diagnostics.allowed_roots');
        config(['capell.diagnostics.allowed_roots' => [$this->root]]);
        $this->baseConfig = array_combine(array_keys(self::BASE_PATH_CONFIG), array_map(config(...), array_keys(self::BASE_PATH_CONFIG)));
        $this->publicationGroups = ServiceProvider::$publishGroups;
        $this->publications = ServiceProvider::$publishes;
        $this->appPath = $application->path();
        $this->basePath = $application->basePath();
        $this->bootstrapPath = $application->bootstrapPath();
        $this->langPath = $application->langPath();
        $this->databasePath = $application->databasePath();
        $this->publicPath = $application->publicPath();
        $application->useAppPath($this->root . '/app');
        config(array_combine(
            array_keys(self::BASE_PATH_CONFIG),
            array_map(fn (string $relative): string => $this->root . '/' . $relative, self::BASE_PATH_CONFIG),
        ));
        $application->setBasePath($this->root);
        new Filesystem()->ensureDirectoryExists($this->root . '/bootstrap/cache');
        file_put_contents($this->root . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
        $application->useDatabasePath($this->root . '/database');
        new Filesystem()->ensureDirectoryExists($this->root . '/database');
        new Filesystem()->ensureDirectoryExists($this->root . '/config');
        $application->usePublicPath($this->root . '/public');
        foreach (ServiceProvider::$publishGroups as $tag => $paths) {
            ServiceProvider::$publishGroups[$tag] = $this->relocate($paths);
        }

        foreach (ServiceProvider::$publishes as $provider => $paths) {
            ServiceProvider::$publishes[$provider] = $this->relocate($paths);
        }
    }

    public function restore(): void
    {
        config(['capell.diagnostics.allowed_roots' => $this->allowedRoots]);
        config(array_combine(array_keys(self::BASE_PATH_CONFIG), $this->baseConfig));
        ServiceProvider::$publishGroups = $this->publicationGroups;
        ServiceProvider::$publishes = $this->publications;
        $this->application->useAppPath($this->appPath);
        $this->application->setBasePath($this->basePath);
        $this->application->useBootstrapPath($this->bootstrapPath);
        $this->application->useLangPath($this->langPath);
        $this->application->useDatabasePath($this->databasePath);
        $this->application->usePublicPath($this->publicPath);
        new Filesystem()->deleteDirectory($this->root);
    }

    /** @param array<string, string> $paths
     * @return array<string, string>
     */
    private function relocate(array $paths): array
    {
        return array_map(fn (string $target): string => str_starts_with($target, $this->publicPath . DIRECTORY_SEPARATOR)
            ? $this->root . '/public' . substr($target, strlen($this->publicPath))
            : $target, $paths);
    }
}
