<?php

declare(strict_types=1);

/*
 * Boots a fresh application from the package's testbench configuration, with a Capell panel served on its
 * own domain and admin-code routes registered before any service provider, in its own process.
 *
 *   php fresh-application.php cache <file>   Writes the route cache to <file>, as `route:cache` does.
 *   php fresh-application.php serve <file>   Boots from the route cache in <file> and prints, as JSON, how
 *                                            each probe request is answered.
 */
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\DomainCapellPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\OldCacheDenyProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StaleCachePublicController;
use Composer\Autoload\ClassLoader;
use Filament\Http\Controllers\RedirectToHomeController;
use Guava\IconPicker\Http\Controllers\IconIndexController;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;
use StandalonePackage\PreviewController;

$root = dirname(__DIR__, 5);
require $root . '/vendor/autoload.php';
$loader = new ClassLoader(__DIR__ . '/../StandalonePackage');
$loader->addPsr4('StandalonePackage\\', __DIR__ . '/../StandalonePackage/src');
$loader->register();

[, $mode, $file] = $argv + [null, null, null];
throw_if(! in_array($mode, ['cache', 'old-cache', 'serve', 'serve-old'], true) || ! is_string($file), InvalidArgumentException::class, 'Usage: fresh-application.php cache|serve <route-cache-file>');

$config = Config::loadFromYaml($root);
// The root package's own providers, which the testbench command discovers from its composer.json.
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$packageProviders = (array) ($composer['extra']['laravel']['providers'] ?? []);
// The configuration holds its environment as KEY=value lines.
$environment = [...array_values(array_filter((array) ($config['env'] ?? []), is_string(...))), 'APP_ROUTES_CACHE=' . $file];
foreach ($environment as $line) {
    [$key, $value] = explode('=', $line, 2) + [1 => ''];
    $value = trim($value, '\'"');
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

if (in_array($mode, ['cache', 'old-cache'], true) && is_file($file)) {
    unlink($file);
}

$app = Application::create(
    basePath: $config['laravel'],
    resolvingCallback: static function (LaravelApplication $app): void {
        // Registered before any service provider, so before the admin package installs its route collection.
        $app->make(Router::class)->get('/runtime-early-admin', RedirectToHomeController::class)->middleware('web');
        // An admin-only plugin's endpoint, registered before anything declares the plugin as admin code.
        $app->make(Router::class)->get('/runtime-early-icons', IconIndexController::class)->middleware('web');
    },
    options: ['extra' => [
        'providers' => [...(array) ($config['providers'] ?? []), ...$packageProviders, DomainCapellPanelProvider::class, ...(in_array($mode, ['old-cache', 'serve-old'], true) ? [OldCacheDenyProvider::class] : [])],
        'dont-discover' => (array) ($config['dont-discover'] ?? []),
    ]],
);

if (in_array($mode, ['cache', 'old-cache'], true)) {
    if ($mode === 'old-cache') {
        $router = $app->make(Router::class);
        $router->get('/runtime-old-named', StaleCachePublicController::class)->name('filament.admin.old-preview');
        $router->get('/runtime-old-middleware', StaleCachePublicController::class)->middleware('panel:admin');
        $router->get('/runtime-old-host', StaleCachePublicController::class);
        $router->get('/admin/ai-creator/sessions/old-preview', PreviewController::class);
        AdminRouteProvenance::panel('admin', static function (): void {
            Route::get('/runtime-security-control', StaleCachePublicController::class)->name('runtime.security-control');
        }, guests: true);
    }

    // As `route:cache` does: refresh the lookups, prepare every route for serialisation and export them.
    $routes = $app->make(Router::class)->getRoutes();
    throw_unless($routes instanceof RouteCollection, RuntimeException::class, 'The routes were already cached.');

    $routes->refreshNameLookups();
    $routes->refreshActionLookups();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    $compiled = $routes->compile();
    if ($mode === 'old-cache') {
        foreach (array_keys($compiled['attributes']) as $name) {
            if ($name !== 'runtime.security-control') {
                unset($compiled['attributes'][$name]['action'][PanelOwnership::ACTION]);
            }
        }
    }

    $stub = file_get_contents($root . '/vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/routes.stub');
    file_put_contents($file, str_replace('{{routes}}', var_export($compiled, true), (string) $stub));
    echo json_encode(['cached' => count($routes->getRoutes())]);
} else {
    // The testbench skeleton has no route service provider, so the cache is loaded as Laravel's own loads it on
    // boot: the cache file hands its compiled routes to the router, replacing every route registered so far.
    throw_unless($app->routesAreCached(), RuntimeException::class, 'The route cache was not found.');

    require $app->getCachedRoutesPath();

    $probes = [
        'security_control' => '/runtime-security-control',
        'old_named' => '/runtime-old-named',
        'old_middleware' => '/runtime-old-middleware',
        'old_package_preview' => '/admin/ai-creator/sessions/old-preview',
        'old_host' => '/runtime-old-host',
        'early_admin' => '/runtime-early-admin',
        'peek_preview' => '/filament-peek/preview',
        'early_plugin' => '/runtime-early-icons',
        'icon_picker' => '/_icon-picker/index',
        'admin_asset' => '/admin/extension-asset',
        'desk' => 'http://' . DomainCapellPanelProvider::DOMAIN . '/desk',
        'desk_elsewhere' => 'http://elsewhere.example.test/desk',
    ];
    $kernel = $app->make(Kernel::class);
    $answers = ['routes_cached' => ! $app->make(Router::class)->getRoutes() instanceof RouteCollection];
    foreach ($probes as $probe => $url) {
        $response = $kernel->handle(Request::create($url));
        $answers[$probe] = ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location')];
    }

    echo json_encode($answers);
}
