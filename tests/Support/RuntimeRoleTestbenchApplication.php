<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;
use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Override;
use RuntimeException;

final class RuntimeRoleTestbenchApplication extends TestbenchApplication
{
    /**
     * Testbench's generated Laravel skeleton uses the App namespace, while the
     * package repository's root Composer manifest uses Workbench\\App for its
     * development fixtures. Fresh Artisan children load the root autoloader, so
     * register the generated application's namespace before Laravel resolves
     * bootstrap/providers.php.
     */
    #[Override]
    public static function create(?string $basePath = null, ?callable $resolvingCallback = null, array $options = []): Application
    {
        self::registerGeneratedApplicationNamespace($basePath);

        return parent::create($basePath, $resolvingCallback, $options);
    }

    #[Override]
    protected function resolveApplicationConfiguration(mixed $app): void
    {
        throw_unless($app instanceof Application, RuntimeException::class, 'Testbench did not resolve a Laravel application.');

        // Testbench loads configuration after resolving environment variables. Set the runtime
        // cache paths first so Laravel reads the role-specific config cache, then apply the
        // provider filter after the configuration repository exists.
        RuntimeRoleBootstrap::configureResolvedEnvironment($app);
        parent::resolveApplicationConfiguration($app);

        // Testbench loads configuration from its skeleton rather than Laravel's cached config
        // file. The role cache path still exists for the runtime contract, so pin this flag to
        // the loader that actually ran; otherwise package providers skip their config merges.
        $app->instance('config_loaded_from_cache', false);

        RuntimeRoleBootstrap::configureResolvedConfiguration($app);
    }

    private static function registerGeneratedApplicationNamespace(?string $basePath): void
    {
        if (! is_string($basePath) || $basePath === '' || ! is_dir($basePath . '/app')) {
            return;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addPsr4('App\\', $basePath . '/app', prepend: true);
        }
    }
}
