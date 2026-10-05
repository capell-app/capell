<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Core\Actions\RuntimeRefresh\PreparePackageRuntimeReloadAction;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class PrepareInstalledExtensionReloadAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Application $app, private readonly Filesystem $files) {}

    public function handle(): void
    {
        try {
            PreparePackageRuntimeReloadAction::run();
            // Rebuilding routes in this application would preserve its old panel topology.
            // The next ordinary request constructs routes from the installed package state.
            $paths = [$this->app->getCachedRoutesPath()];
            foreach (Filament::getPanels() as $panel) {
                $paths[] = $panel->getComponentCachePath();
            }

            foreach ($paths as $path) {
                if ($this->files->exists($path)) {
                    $this->files->delete($path);
                }

                if ($this->files->exists($path)) {
                    throw new RuntimeException(__('capell-admin::message.extension_activation_pending'));
                }
            }
        } catch (Throwable $throwable) {
            $this->app->make(InstalledRuntimeLifecycle::class)->invalidate();

            throw $throwable;
        }
    }
}
