<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Support\Components\ComponentRegistry;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** Reconcile persisted activation caches for every lifecycle entry point. */
final class PreparePackageRuntimeReloadAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Application $app, private readonly Filesystem $files) {}

    public function handle(): void
    {
        resolve(CapellPackageRegistry::class)->clearExtensionCacheOrFail();

        // Rebuilding in this process would preserve its pre-install panel topology.
        foreach ([RefreshRouteCacheAction::run(rebuild: false), RefreshConfigurationCacheAction::run(rebuild: false)] as $stage) {
            if (! $stage->passed) {
                throw new RuntimeException(__('capell::runtime-refresh.cache_refresh_required') . ' ' . $stage->message);
            }
        }

        foreach ([$this->app->getCachedRoutesPath(), $this->app->getCachedConfigPath()] as $path) {
            if ($this->files->exists($path)) {
                throw new RuntimeException(__('capell::runtime-refresh.cache_refresh_required'));
            }
        }

        resolve(ComponentRegistry::class)->clearCachedComponentsOrFail();
    }
}
