<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Support\Patching\RootRouteEditor;
use RuntimeException;
use Throwable;

final class WelcomeRouteInstaller
{
    private const string ROUTES_WEB_PATH = 'routes/web.php';

    public function canInstall(): bool
    {
        return $this->hasRootRoute();
    }

    public function hasRootRoute(): bool
    {
        $routesWebPath = $this->routesWebPath();

        if (! file_exists($routesWebPath)) {
            return false;
        }

        try {
            $content = file_get_contents($routesWebPath);

            return $content === false || new RootRouteEditor($content)->hasRootRoute();
        } catch (Throwable) {
            return true;
        }
    }

    public function hasStockWelcomeRoute(): bool
    {
        $routesWebPath = $this->routesWebPath();

        if (! file_exists($routesWebPath)) {
            return false;
        }

        try {
            $content = file_get_contents($routesWebPath);

            return $content !== false && new RootRouteEditor($content)->hasStockWelcomeRoute();
        } catch (Throwable) {
            return false;
        }
    }

    public function install(): bool
    {
        if (! $this->hasStockWelcomeRoute()) {
            return false;
        }

        $routesWebPath = $this->routesWebPath();
        $content = file_get_contents($routesWebPath);
        throw_if($content === false, RuntimeException::class, 'Unable to read routes/web.php.');

        $routes = new RootRouteEditor($content);
        if (! $routes->hasStockWelcomeRoute()) {
            return false;
        }

        $this->configureFrontendHomeRoute(register: true);
        $content = $routes->removeStockWelcomeRoutes();

        throw_if(file_put_contents($routesWebPath, $content) === false, RuntimeException::class, 'Unable to write routes/web.php.');

        return true;
    }

    public function disableFrontendHomeRoute(): void
    {
        $this->configureFrontendHomeRoute(register: false);
    }

    public function enableFrontendHomeRoute(): void
    {
        $this->configureFrontendHomeRoute(register: true);
    }

    private function configureFrontendHomeRoute(bool $register): void
    {
        $envPath = $this->envPath();

        if (! file_exists($envPath)) {
            file_put_contents($envPath, '');
        }

        $content = file_get_contents($envPath);
        throw_if($content === false, RuntimeException::class, 'Unable to read .env.');

        $value = $register ? 'true' : 'false';
        $line = 'CAPELL_FRONTEND_REGISTER_HOME_ROUTE=' . $value;

        if (preg_match('/^CAPELL_FRONTEND_REGISTER_HOME_ROUTE=.*$/m', $content) === 1) {
            $content = preg_replace('/^CAPELL_FRONTEND_REGISTER_HOME_ROUTE=.*$/m', $line, $content) ?? $content;
        } else {
            $content = rtrim($content) . (PHP_EOL . $line . PHP_EOL);
        }

        throw_if(file_put_contents($envPath, $content) === false, RuntimeException::class, 'Unable to write .env.');
    }

    private function routesWebPath(): string
    {
        return config('capell.install.welcome_routes_web_path', base_path(self::ROUTES_WEB_PATH));
    }

    private function envPath(): string
    {
        return config('capell.install.welcome_env_path', base_path('.env'));
    }
}
