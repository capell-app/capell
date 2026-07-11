<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

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
        } catch (Throwable) {
            return true;
        }

        if ($content === false) {
            return true;
        }

        return preg_match('/Route::\w+\s*\(\s*[\'"][\/]["\']\s*[,\)]/', $content) === 1;
    }

    public function hasStockWelcomeRoute(): bool
    {
        $routesWebPath = $this->routesWebPath();

        if (! file_exists($routesWebPath)) {
            return false;
        }

        try {
            $content = file_get_contents($routesWebPath);
        } catch (Throwable) {
            return false;
        }

        if ($content === false) {
            return false;
        }

        return $this->containsStockWelcomeRoute($content);
    }

    public function install(): bool
    {
        if (! $this->hasRootRoute()) {
            return false;
        }

        $this->configureFrontendHomeRoute(register: true);

        $routesWebPath = $this->routesWebPath();
        $routesDirectory = dirname($routesWebPath);

        throw_if(! is_dir($routesDirectory) && ! mkdir($routesDirectory, 0755, true) && ! is_dir($routesDirectory), RuntimeException::class, 'Unable to create routes directory.');

        $content = file_exists($routesWebPath) ? file_get_contents($routesWebPath) : null;
        if ($content === false || $content === null || trim($content) === '') {
            $content = <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

PHP;
        }

        $content = $this->removeHomeRoute($content);

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

    private function removeHomeRoute(string $content): string
    {
        foreach ($this->homeRoutePatterns() as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $content = preg_replace($pattern, '', $content, 1) ?? $content;

                return preg_replace('/\n\n\n+/', "\n\n", $content) ?? $content;
            }
        }

        return $content;
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

    private function containsStockWelcomeRoute(string $content): bool
    {
        return array_any($this->stockWelcomeRoutePatterns(), fn ($pattern): bool => preg_match($pattern, $content) === 1);
    }

    private function routesWebPath(): string
    {
        return config('capell.install.welcome_routes_web_path', base_path(self::ROUTES_WEB_PATH));
    }

    private function envPath(): string
    {
        return config('capell.install.welcome_env_path', base_path('.env'));
    }

    /** @return array<int, string> */
    private function homeRoutePatterns(): array
    {
        return [
            ...$this->stockWelcomeRoutePatterns(),
            '/Route::\w+\s*\(\s*[\'"][\/]["\']\s*,\s*(?:static\s+)?fn\s*\([^)]*\)\s*=>[^;]+?\)\s*(?:->\w+\s*\([^)]*\))*\s*;/',
            '/Route::\w+\s*\(\s*[\'"][\/]["\']\s*,\s*[^;\n]+?\)\s*(?:->\w+\s*\([^)]*\))*\s*;/',
            '/Route::\w+\s*\(\s*[\'"][\/]["\']\s*,\s*(?:static\s+)?function\s*\([^)]*\)\s*\{.*?\}\s*\)\s*(?:->\w+\s*\([^)]*\))*\s*;/s',
        ];
    }

    /** @return array<int, string> */
    private function stockWelcomeRoutePatterns(): array
    {
        return [
            '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*(?:static\s+)?fn\s*\(\s*\)\s*=>\s*view\s*\(\s*[\'"]welcome[\'"]\s*\)\s*\)\s*;/',
            '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*(?:static\s+)?function\s*\(\s*\)\s*\{[^}]*return\s+view\s*\(\s*[\'"]welcome["\']\s*\)\s*;[^}]*\}\s*\)\s*;/',
            '/Route::view\s*\(\s*[\'"][\/]["\']\s*,\s*[\'"]welcome[\'"]\s*\)\s*(?:->name\s*\(\s*[\'"]home[\'"]\s*\))?\s*;/',
        ];
    }
}
