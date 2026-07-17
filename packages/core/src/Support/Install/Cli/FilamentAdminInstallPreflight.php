<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Closure;
use Illuminate\Support\Collection;

use function Laravel\Prompts\confirm;

use Throwable;

final class FilamentAdminInstallPreflight
{
    /**
     * @param  Collection<string, PackageData>  $packages
     * @param  Closure(string): void  $writeError
     */
    public function ensureReady(
        Collection $packages,
        bool $interactive,
        bool $useFreshDemoDefaults,
        ProgressReporter $reporter,
        Closure $writeError,
    ): bool {
        if (! $packages->has('capell-app/admin')) {
            return true;
        }

        if (! $this->hasPanelProvider()) {
            if ($interactive
                && ! $useFreshDemoDefaults
                && ! confirm(
                    label: 'The Capell admin package requires a Filament panel. Would you like to install Filament now?',
                    default: true,
                )) {
                $writeError('Filament must be installed before installing the Capell admin package.');

                return false;
            }

            try {
                InstallFilamentPanelAction::run($reporter);
            } catch (Throwable $throwable) {
                $writeError($throwable->getMessage());

                return false;
            }
        }

        $this->registerPanelProviders();

        if (! $this->hasPanelProvider()) {
            $writeError('Filament panel installation did not create an AdminPanelProvider. Run `php artisan filament:install --panels` manually, then rerun `php artisan capell:install`.');

            return false;
        }

        return true;
    }

    public function hasInstalledPanelProvider(): bool
    {
        return $this->hasPanelProvider();
    }

    private function registerPanelProviders(): void
    {
        foreach ($this->panelProviderPaths() as $path) {
            $relativePath = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $path);
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (! class_exists($class)) {
                require_once $path;
            }

            if (class_exists($class)) {
                app()->register($class);
            }
        }
    }

    private function hasPanelProvider(): bool
    {
        return $this->panelProviderPaths() !== [];
    }

    /**
     * @return array<int, string>
     */
    private function panelProviderPaths(): array
    {
        $paths = glob(app_path('Providers/Filament/*PanelProvider.php'));

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, is_file(...)));
    }
}
